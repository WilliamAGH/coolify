#!/usr/bin/env python3

import argparse
import base64
import hashlib
from pathlib import Path
import socket
import socketserver
import struct
import time
from http.server import BaseHTTPRequestHandler, HTTPServer


class DualStackServer(socketserver.ThreadingMixIn, HTTPServer):
    address_family = socket.AF_INET6
    daemon_threads = True
    allow_reuse_address = True

    def server_bind(self) -> None:
        self.socket.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
        super().server_bind()


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "control-plane-port8000-lab"

    def log_message(self, format_string: str, *args: object) -> None:
        return

    def response_headers(
        self, length: int, content_type: str = "text/plain", applied_config: str | None = None
    ) -> None:
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(length))
        self.send_header("X-Backend-Color", self.server.color)
        self.send_header("X-Observed-Host", self.headers.get("Host", ""))
        self.send_header("X-Observed-Forwarded-For", self.headers.get("X-Forwarded-For", ""))
        self.send_header("X-Observed-Forwarded-Proto", self.headers.get("X-Forwarded-Proto", ""))
        self.send_header("X-Observed-Forwarded-Host", self.headers.get("X-Forwarded-Host", ""))
        self.send_header("X-Observed-Real-IP", self.headers.get("X-Real-IP", ""))
        self.send_header("X-Observed-Forwarded", self.headers.get("Forwarded", "absent"))
        if applied_config is not None:
            self.send_header("X-Control-Plane-Applied-Config", applied_config)
            if self.server.duplicate_ack:
                self.send_header("X-Control-Plane-Applied-Config", applied_config)
        self.end_headers()

    def do_GET(self) -> None:
        if self.path.startswith("/api/control-plane/probe"):
            if self.headers.get("X-Control-Plane-Probe") != self.server.probe_token:
                self.send_error(403)
                return
            body = f"probe:{self.server.color}\n".encode()
            self.response_headers(len(body), applied_config=self.server.applied_config)
            self.wfile.write(body)
            return
        if self.headers.get("Upgrade", "").lower() == "websocket":
            self.websocket()
            return
        if self.path.startswith("/long"):
            time.sleep(6)
            body = f"long:{self.server.color}\n".encode()
            self.response_headers(len(body))
            self.wfile.write(body)
            return
        if self.path.startswith("/sse"):
            chunks = [f"data: {self.server.color}-{index}\n\n".encode() for index in range(8)]
            body_length = sum(len(chunk) for chunk in chunks)
            self.response_headers(body_length, "text/event-stream")
            for chunk in chunks:
                self.wfile.write(chunk)
                self.wfile.flush()
                time.sleep(0.5)
            return
        body = f"ok:{self.server.color}:{self.path}\n".encode()
        self.response_headers(len(body))
        self.wfile.write(body)

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        payload = self.rfile.read(length)
        key = self.headers.get("Idempotency-Key", "missing")
        digest = hashlib.sha256(payload).hexdigest()
        body = f"write:{self.server.color}:{key}:{digest}\n".encode()
        self.response_headers(len(body))
        self.wfile.write(body)

    def websocket(self) -> None:
        key = self.headers.get("Sec-WebSocket-Key", "")
        accept = base64.b64encode(
            hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()
        ).decode()
        self.send_response(101, "Switching Protocols")
        self.send_header("Upgrade", "websocket")
        self.send_header("Connection", "Upgrade")
        self.send_header("Sec-WebSocket-Accept", accept)
        self.end_headers()
        self.connection.settimeout(15)
        first = self.rfile.read(2)
        if len(first) != 2:
            return
        payload_length = first[1] & 0x7F
        if payload_length == 126:
            payload_length = struct.unpack("!H", self.rfile.read(2))[0]
        mask = self.rfile.read(4)
        payload = bytes(byte ^ mask[index % 4] for index, byte in enumerate(self.rfile.read(payload_length)))
        time.sleep(4)
        self.connection.sendall(bytes([0x81, len(payload)]) + payload)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--color", required=True)
    parser.add_argument("--probe-token-file", required=True)
    parser.add_argument("--applied-config-file", required=True)
    parser.add_argument("--duplicate-ack", action="store_true")
    arguments = parser.parse_args()
    server = DualStackServer(("::", arguments.port), Handler)
    server.color = arguments.color
    server.probe_token = Path(arguments.probe_token_file).read_text()
    server.applied_config = Path(arguments.applied_config_file).read_text()
    server.duplicate_ack = arguments.duplicate_ack
    server.serve_forever()


if __name__ == "__main__":
    main()
