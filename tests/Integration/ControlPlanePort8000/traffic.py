#!/usr/bin/env python3

import argparse
import base64
import hashlib
import http.client
import os
import socket
import struct
import threading
import time
import urllib.request


def request(url: str, path: str = "/api/health") -> bytes:
    with urllib.request.urlopen(url + path, timeout=15) as response:
        if response.status != 200:
            raise RuntimeError(f"unexpected status {response.status}")
        return response.read()


def continuous(url: str, count: int, output: str) -> None:
    failures = []
    for index in range(count):
        try:
            request(url, f"/api/health?request={index}")
        except Exception as error:  # noqa: BLE001 - the evidence must retain every transport failure.
            failures.append(f"{index}:{error}")
        time.sleep(0.02)
    with open(output, "w", encoding="utf-8") as evidence:
        evidence.write("\n".join(failures))
    if failures:
        raise RuntimeError(f"continuous traffic had {len(failures)} failures")


def held_keepalive(host: str, port: int, output: str) -> None:
    connection = http.client.HTTPConnection(host, port, timeout=15)
    connection.request("GET", "/api/health", headers={"Host": "coolify.test:8000"})
    first = connection.getresponse()
    first.read()
    if first.status != 200:
        raise RuntimeError("first held keepalive request failed")
    time.sleep(5)
    connection.request("GET", "/api/health", headers={"Host": "coolify.test:8000"})
    second = connection.getresponse()
    second.read()
    connection.close()
    if second.status != 200:
        raise RuntimeError("second held keepalive request failed")
    with open(output, "w", encoding="utf-8") as evidence:
        evidence.write("held_keepalive=pass\n")


def websocket(host: str, port: int, output: str) -> None:
    key = base64.b64encode(os.urandom(16)).decode()
    connection = socket.create_connection((host, port), timeout=15)
    connection.sendall(
        (
            "GET /ws HTTP/1.1\r\n"
            "Host: coolify.test:8000\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n\r\n"
        ).encode()
    )
    headers = b""
    while b"\r\n\r\n" not in headers:
        headers += connection.recv(4096)
    expected = base64.b64encode(
        hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()
    )
    if b"101 Switching Protocols" not in headers or expected not in headers:
        raise RuntimeError("websocket handshake failed")
    payload = b"held-websocket"
    mask = os.urandom(4)
    masked = bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload))
    connection.sendall(bytes([0x81, 0x80 | len(payload)]) + mask + masked)
    first = connection.recv(2)
    length = first[1] & 0x7F
    echoed = connection.recv(length)
    connection.close()
    if echoed != payload:
        raise RuntimeError("websocket echo failed")
    with open(output, "w", encoding="utf-8") as evidence:
        evidence.write("websocket=pass\n")


def write_request(url: str, path: str, output: str) -> None:
    with open(output, "wb") as evidence:
        evidence.write(request(url, path))


def concurrent_protocols(url: str, output_directory: str) -> None:
    os.makedirs(output_directory, exist_ok=True)
    failures = []
    failure_lock = threading.Lock()

    def run_and_record(name: str, target, *arguments) -> None:
        try:
            target(*arguments)
        except Exception as error:  # noqa: BLE001 - all protocol failures are test failures.
            with failure_lock:
                failures.append(f"{name}:{error}")

    threads = [
        threading.Thread(
            target=run_and_record,
            args=("keepalive", held_keepalive, "127.0.0.1", 8000, f"{output_directory}/keepalive"),
        ),
        threading.Thread(
            target=run_and_record,
            args=("websocket", websocket, "127.0.0.1", 8000, f"{output_directory}/websocket"),
        ),
        threading.Thread(
            target=run_and_record,
            args=("long", write_request, url, "/long", f"{output_directory}/long"),
        ),
        threading.Thread(
            target=run_and_record,
            args=("sse", write_request, url, "/sse", f"{output_directory}/sse"),
        ),
    ]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()
    if failures:
        with open(f"{output_directory}/failures", "w", encoding="utf-8") as evidence:
            evidence.write("\n".join(failures))
        raise RuntimeError(f"concurrent protocol traffic failed: {'; '.join(failures)}")


def main() -> None:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="mode", required=True)
    continuous_parser = subparsers.add_parser("continuous")
    continuous_parser.add_argument("--url", required=True)
    continuous_parser.add_argument("--count", type=int, default=500)
    continuous_parser.add_argument("--output", required=True)
    protocols_parser = subparsers.add_parser("protocols")
    protocols_parser.add_argument("--url", required=True)
    protocols_parser.add_argument("--output-directory", required=True)
    arguments = parser.parse_args()
    if arguments.mode == "continuous":
        continuous(arguments.url, arguments.count, arguments.output)
    else:
        concurrent_protocols(arguments.url, arguments.output_directory)


if __name__ == "__main__":
    main()
