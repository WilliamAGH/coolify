#!/usr/bin/env python3

import os
import subprocess
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


class ControlPlaneHandler(SimpleHTTPRequestHandler):
    scripts = {
        "/cgi-bin/probe": Path("/srv/www/cgi-bin/probe"),
        "/cgi-bin/request": Path("/srv/www/cgi-bin/request"),
        "/api/control-plane/route-health": Path("/srv/www/cgi-bin/route-health"),
    }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory="/srv/www", **kwargs)

    def run_script(self) -> bool:
        path, _, query = self.path.partition("?")
        script = self.scripts.get(path)
        if script is None:
            return False

        environment = os.environ.copy()
        environment.update({
            "REQUEST_METHOD": self.command,
            "QUERY_STRING": query,
            "REMOTE_ADDR": self.client_address[0],
        })
        for name, value in self.headers.items():
            environment[f"HTTP_{name.upper().replace('-', '_')}"] = value

        result = subprocess.run(
            [script],
            env=environment,
            capture_output=True,
            check=False,
        )
        header_bytes, separator, body = result.stdout.partition(b"\n\n")
        if result.returncode != 0 or separator == b"":
            self.send_error(500)
            return True

        status = 200
        headers = []
        for raw_line in header_bytes.splitlines():
            name, value = raw_line.decode("utf-8").split(":", 1)
            if name.lower() == "status":
                status = int(value.strip().split(" ", 1)[0])
            else:
                headers.append((name, value.strip()))

        self.send_response(status)
        for name, value in headers:
            self.send_header(name, value)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)
        return True

    def do_GET(self) -> None:
        if self.run_script():
            return
        if self.path != "/api/health":
            self.send_error(404)
            return
        super().do_GET()

    def do_HEAD(self) -> None:
        if self.run_script():
            return
        if self.path != "/api/health":
            self.send_error(404)
            return
        super().do_HEAD()

    def do_OPTIONS(self) -> None:
        if not self.run_script():
            self.send_error(404)

    def do_POST(self) -> None:
        if not self.run_script():
            self.send_error(404)

    do_DELETE = do_POST
    do_PATCH = do_POST
    do_PUT = do_POST


server = ThreadingHTTPServer(
    ("0.0.0.0", int(os.environ.get("CONTROL_PLANE_BACKEND_PORT", "8080"))),
    ControlPlaneHandler,
)
server.serve_forever()
