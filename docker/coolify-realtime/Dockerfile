# syntax=docker/dockerfile:1

# Versions
# https://github.com/soketi/soketi/releases
ARG SOKETI_VERSION=1.6-16-alpine@sha256:5e45fe1adbf2d4ef8022d0126a3c7e4371b7b08f35784b76a2dc353954ee885c
# https://hub.docker.com/_/node
ARG NODE_VERSION=24-alpine@sha256:a0b9bf06e4e6193cf7a0f58816cc935ff8c2a908f81e6f1a95432d679c54fbfd
# https://hub.docker.com/_/golang
ARG CLOUDFLARED_GO_IMAGE=golang:1.26.5-alpine@sha256:0178a641fbb4858c5f1b48e34bdaabe0350a330a1b1149aabd498d0699ff5fb2
# https://github.com/cloudflare/cloudflared/releases/tag/2026.7.1
ARG CLOUDFLARED_VERSION=2026.7.1
ARG CLOUDFLARED_TAG=2026.7.1
# The 2026.7.1 annotated tag resolves to this immutable source commit.
ARG CLOUDFLARED_COMMIT=ecb88678f1368581964ca2bb55615502a2ed2dbe
ARG CLOUDFLARED_SOURCE_SHA256=94a8f8e057bf4eec41416d6db56a5f8379b833fd40fdce4dcdc668b8cb6b5c65
ARG CLOUDFLARED_RELEASE_DATE=2026-07-09-12:06

FROM --platform=$BUILDPLATFORM ${CLOUDFLARED_GO_IMAGE} AS cloudflared-builder

ARG TARGETARCH
ARG CLOUDFLARED_VERSION
ARG CLOUDFLARED_COMMIT
ARG CLOUDFLARED_SOURCE_SHA256
ARG CLOUDFLARED_RELEASE_DATE

WORKDIR /src

RUN apk add --no-cache ca-certificates curl tar

RUN set -eux; \
    curl --fail --location --silent --show-error "https://github.com/cloudflare/cloudflared/archive/${CLOUDFLARED_COMMIT}.tar.gz" --output /tmp/cloudflared.tar.gz; \
    echo "${CLOUDFLARED_SOURCE_SHA256}  /tmp/cloudflared.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/cloudflared.tar.gz --strip-components=1; \
    test -f vendor/modules.txt

RUN --mount=type=cache,target=/root/.cache/go-build \
    GOPROXY=off \
    GOOS=linux \
    GOARCH="${TARGETARCH}" \
    CGO_ENABLED=0 \
    go build \
        -mod=vendor \
        -trimpath \
        -buildvcs=false \
        -ldflags="-X main.Version=${CLOUDFLARED_VERSION} -X \"main.BuildTime=${CLOUDFLARED_RELEASE_DATE} UTC\"" \
        -o /out/cloudflared \
        github.com/cloudflare/cloudflared/cmd/cloudflared

FROM node:${NODE_VERSION} AS node-runtime

FROM quay.io/soketi/soketi:${SOKETI_VERSION}

WORKDIR /terminal
RUN cp /usr/local/bin/node /usr/local/bin/node-soketi
COPY --from=node-runtime /usr/local/bin/node /usr/local/bin/node
COPY --from=node-runtime /usr/local/bin/npm /usr/local/bin/npm
COPY --from=node-runtime /usr/local/bin/npx /usr/local/bin/npx
COPY --from=node-runtime /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=node-runtime /usr/lib/libstdc++.so.6.0.34 /usr/local/lib/node24/libstdc++.so.6
COPY --from=node-runtime /usr/lib/libgcc_s.so.1 /usr/local/lib/node24/libgcc_s.so.1
ENV LD_LIBRARY_PATH=/usr/local/lib/node24
RUN apk upgrade --no-cache && \
    apk add --no-cache openssh-client make g++ python3 curl
COPY docker/coolify-realtime/package*.json ./
RUN npm ci
RUN npm rebuild node-pty
COPY docker/coolify-realtime/soketi-entrypoint.sh /soketi-entrypoint.sh
COPY docker/coolify-realtime/terminal-server.js /terminal/terminal-server.js
COPY docker/coolify-realtime/terminal-utils.js /terminal/terminal-utils.js

COPY --from=cloudflared-builder --chmod=755 /out/cloudflared /usr/local/bin/cloudflared

ENTRYPOINT ["/bin/sh", "/soketi-entrypoint.sh"]
