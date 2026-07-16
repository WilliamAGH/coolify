#!/bin/sh

set -eu

expected_token=$(cat /run/secrets/control-plane-direct-probe-token)
expected_ack=$(cat /run/secrets/control-plane-applied-ack)
request_path=
observed_token=
while IFS= read -r request_line; do
    request_line=${request_line%"$(printf '\r')"}
    [ -n "$request_line" ] || break
    case $request_line in
        GET\ *) request_path=${request_line#GET }; request_path=${request_path%% *} ;;
        X-Control-Plane-Probe:\ *) observed_token=${request_line#*: } ;;
    esac
done
if [ "$request_path" = /api/health ]; then
    printf 'HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n'
elif [ "$request_path" = /api/control-plane/probe ] \
    && [ "$observed_token" = "$expected_token" ]; then
    printf 'HTTP/1.1 204 No Content\r\nX-Control-Plane-Applied-Config: %s\r\nConnection: close\r\n\r\n' \
        "$expected_ack"
else
    printf 'HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n'
fi
