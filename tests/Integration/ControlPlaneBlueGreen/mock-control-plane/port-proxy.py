#!/usr/bin/env python3

import http.client
import http.server
import os
import re
import threading
from dataclasses import dataclass
from pathlib import Path


ROUTE_FIELDS = ("backend", "port", "ack", "owner", "color")
BACKEND_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,126}$")
ACK_PATTERN = re.compile(r"^[A-Za-z0-9._:-]{16,128}$")
OWNER_VALUES = frozenset({"legacy", "bootstrap-a", "permanent-b"})
COLOR_VALUES = frozenset({"legacy", "green", "blue"})


class RouteConfigurationError(ValueError):
    pass


class RouteConfigurationUnavailable(RuntimeError):
    pass


@dataclass(frozen=True)
class Route:
    backend: str
    port: int
    ack: str
    owner: str
    color: str


def parse_route_configuration(configuration: str) -> Route:
    values = {}
    fields = []
    for line in configuration.splitlines():
        if line.count("=") != 1:
            raise RouteConfigurationError("route configuration line is malformed")
        field, value = line.split("=", 1)
        if field not in ROUTE_FIELDS:
            raise RouteConfigurationError("route configuration field is unknown")
        if field in values:
            raise RouteConfigurationError("route configuration field is duplicated")
        values[field] = value
        fields.append(field)
    if tuple(fields) != ROUTE_FIELDS:
        raise RouteConfigurationError("route configuration inventory is incomplete or reordered")
    if not BACKEND_PATTERN.fullmatch(values["backend"]):
        raise RouteConfigurationError("route backend is malformed")
    if not re.fullmatch(r"[1-9][0-9]{0,4}", values["port"]) or int(values["port"]) > 65535:
        raise RouteConfigurationError("route port is malformed")
    if values["owner"] not in OWNER_VALUES:
        raise RouteConfigurationError("route owner is malformed")
    if values["color"] not in COLOR_VALUES:
        raise RouteConfigurationError("route color is malformed")
    if values["color"] == "legacy":
        if values["ack"] != "none" or values["owner"] != "legacy":
            raise RouteConfigurationError("legacy route acknowledgement or owner is malformed")
    elif not ACK_PATTERN.fullmatch(values["ack"]) or values["owner"] == "legacy":
        raise RouteConfigurationError("candidate route acknowledgement or owner is malformed")
    return Route(
        backend=values["backend"],
        port=int(values["port"]),
        ack=values["ack"],
        owner=values["owner"],
        color=values["color"],
    )


class RouteConfiguration:
    def __init__(self, path: Path):
        self.path = path
        self.lock = threading.Lock()
        self.last_complete = None

    def current(self) -> Route:
        with self.lock:
            try:
                candidate = parse_route_configuration(self.path.read_text(encoding="utf-8"))
            except (OSError, UnicodeError, RouteConfigurationError) as error:
                if self.last_complete is not None:
                    return self.last_complete
                raise RouteConfigurationUnavailable("route configuration is unavailable") from error
            self.last_complete = candidate
        return candidate


class Proxy(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    route_configuration: RouteConfiguration
    expected_host: str

    def proxy(self):
        try:
            route = self.route_configuration.current()
        except RouteConfigurationUnavailable:
            self.route_configuration_unavailable()
            return
        body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        connection = http.client.HTTPConnection(route.backend, route.port, timeout=5)
        headers = {key: value for key, value in self.headers.items() if key.lower() != "connection"}
        headers["Host"] = self.headers.get("Host", self.expected_host)
        headers["X-Forwarded-For"] = self.client_address[0]
        headers["X-Forwarded-Proto"] = "http"
        try:
            connection.request(self.command, self.path, body=body, headers=headers)
            response = connection.getresponse()
            payload = response.read()
        except (http.client.HTTPException, OSError):
            connection.close()
            self.upstream_unavailable()
            return
        connection.close()
        self.send_response(response.status)
        for key, value in response.getheaders():
            if key.lower() not in {"connection", "content-length"}:
                self.send_header(key, value)
        self.send_header("X-Control-Plane-Port-Owner", route.owner)
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def route_configuration_unavailable(self):
        payload = b"route configuration unavailable\n"
        self.send_response(503)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)
        self.close_connection = True

    def upstream_unavailable(self):
        payload = b"upstream unavailable\n"
        self.send_response(502)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)
        self.close_connection = True

    do_GET = proxy
    do_HEAD = proxy
    do_OPTIONS = proxy
    do_POST = proxy

    def log_message(self, _format, *_args):
        return


def main():
    configuration_path = Path(os.environ["CONTROL_PLANE_LAB_PORT_CONFIG"])
    expected_host_value = os.environ["LAB_EXPECTED_HOST"]

    class ConfiguredProxy(Proxy):
        route_configuration = RouteConfiguration(configuration_path)
        expected_host = expected_host_value

    http.server.ThreadingHTTPServer(("0.0.0.0", 8000), ConfiguredProxy).serve_forever()


if __name__ == "__main__":
    main()
