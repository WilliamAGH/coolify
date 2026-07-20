# syntax=docker/dockerfile:1@sha256:87999aa3d42bdc6bea60565083ee17e86d1f3339802f543c0d03998580f9cb89

# Versions
# https://github.com/soketi/soketi/releases/tag/1.6.1
ARG SOKETI_VERSION=1.6.1
ARG SOKETI_TAG=1.6.1
ARG SOKETI_COMMIT=5d188786beaf683aca2115a6247dcdc15c29ac77
ARG SOKETI_SOURCE_SHA256=c9221612c7d94700c2044dacbabd92a3939942c9c5dfc80613b0ee073d8884e9
# Soketi is archived, so refresh its production-only dependency graph explicitly
# after compiling against the release's original TypeScript declarations.
ARG SOKETI_RUNTIME_AXIOS_VERSION=1.18.1
ARG SOKETI_RUNTIME_BASIC_FTP_VERSION=5.3.1
ARG SOKETI_RUNTIME_BRACES_VERSION=3.0.3
ARG SOKETI_RUNTIME_FOLLOW_REDIRECTS_VERSION=1.16.0
ARG SOKETI_RUNTIME_FORM_DATA_VERSION=4.0.6
ARG SOKETI_RUNTIME_LODASH_VERSION=4.18.1
ARG SOKETI_RUNTIME_MINIMATCH_VERSION=5.1.9
ARG SOKETI_RUNTIME_MSGPACKR_VERSION=1.12.1
ARG SOKETI_RUNTIME_MYSQL2_VERSION=3.23.1
ARG SOKETI_RUNTIME_PICOMATCH_VERSION=2.3.2
ARG SOKETI_RUNTIME_PM2_AGENT_VERSION=2.1.1
ARG SOKETI_RUNTIME_PM2_AGENT_WS_VERSION=7.5.13
ARG SOKETI_RUNTIME_PM2_VERSION=7.0.3
ARG SOKETI_RUNTIME_LOCK_SHA256=92397a4262e3fa2f2cc9e554ba724981a4a8b97ebfd7f422b38616b68981e89c
# Soketi 1.6.1 pins uWebSockets.js 20.10.0, whose prebuilt addon predates Node 24.
# Build the current compatible addon from its source and package commits instead.
ARG UWEBSOCKETS_VERSION=20.69.0
ARG UWEBSOCKETS_TAG=v20.69.0
ARG UWEBSOCKETS_SOURCE_COMMIT=faf115275bb9c55edf739a06406849e42e89ec04
ARG UWEBSOCKETS_PACKAGE_COMMIT=dddd8ffd1b2c28a66022160923ca92f064cdacb4
ARG UWEBSOCKETS_NO_HTTP3_PATCH_SHA256=418c90db5f48f0893d53a41c84c549138c47307c331ffed5146e17cefa3da2ad
ARG NODE_V8_FAST_API_HEADER_SHA256=c6c8b22ebf8014ef5b1cb04bec2af7549754cede52b745474aae1762e060b842
# https://hub.docker.com/_/node
ARG NODE_VERSION=24.18.0-alpine3.24@sha256:a0b9bf06e4e6193cf7a0f58816cc935ff8c2a908f81e6f1a95432d679c54fbfd
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

FROM node:${NODE_VERSION} AS node-build-base

RUN apk add --no-cache build-base curl git libuv openssl python3 tar zlib

FROM node-build-base AS uwebsockets-builder

ARG TARGETARCH
ARG UWEBSOCKETS_VERSION
ARG UWEBSOCKETS_SOURCE_COMMIT
ARG UWEBSOCKETS_PACKAGE_COMMIT
ARG UWEBSOCKETS_NO_HTTP3_PATCH_SHA256
ARG NODE_V8_FAST_API_HEADER_SHA256

WORKDIR /src/uwebsockets

COPY docker/coolify-realtime/uwebsockets-no-http3.patch /tmp/uwebsockets-no-http3.patch

RUN apk add --no-cache \
        clang \
        libuv-dev \
        linux-headers \
        openssl-dev \
        zlib-dev

RUN set -eux; \
    git init .; \
    git remote add origin https://github.com/uNetworking/uWebSockets.js.git; \
    git fetch --depth=1 origin "${UWEBSOCKETS_SOURCE_COMMIT}"; \
    test "$(git rev-parse FETCH_HEAD)" = "${UWEBSOCKETS_SOURCE_COMMIT}"; \
    git checkout --detach FETCH_HEAD; \
    git submodule update --init --depth=1 uWebSockets; \
    git -C uWebSockets submodule update --init --depth=1 uSockets; \
    test "$(git rev-parse HEAD)" = "${UWEBSOCKETS_SOURCE_COMMIT}"

RUN set -eux; \
    curl --fail --location --silent --show-error \
        https://raw.githubusercontent.com/nodejs/node/v24.18.0/deps/v8/include/v8-fast-api-calls.h \
        --output /usr/local/include/node/v8-fast-api-calls.h; \
    echo "${NODE_V8_FAST_API_HEADER_SHA256}  /usr/local/include/node/v8-fast-api-calls.h" | sha256sum -c -; \
    echo "${UWEBSOCKETS_NO_HTTP3_PATCH_SHA256}  /tmp/uwebsockets-no-http3.patch" | sha256sum -c -; \
    git apply --check --unidiff-zero /tmp/uwebsockets-no-http3.patch; \
    git apply --unidiff-zero /tmp/uwebsockets-no-http3.patch; \
    clang \
        -DWIN32_LEAN_AND_MEAN \
        -DLIBUS_USE_LIBUV \
        -DLIBUS_USE_OPENSSL \
        -flto -O3 -pthread -c -fPIC \
        -I /usr/local/include/node \
        -I uWebSockets/uSockets/src \
        uWebSockets/uSockets/src/*.c \
        uWebSockets/uSockets/src/eventing/*.c \
        uWebSockets/uSockets/src/crypto/*.c; \
    clang++ \
        -DWIN32_LEAN_AND_MEAN \
        -DUWS_WITH_PROXY \
        -DUWS_REMOTE_ADDRESS_USERSPACE \
        -DLIBUS_USE_LIBUV \
        -DLIBUS_USE_OPENSSL \
        -flto -O3 -pthread -c -fPIC -std=c++20 \
        -I /usr/local/include/node \
        -I uWebSockets/uSockets/src \
        -I uWebSockets/src \
        src/addon.cpp \
        uWebSockets/uSockets/src/crypto/sni_tree.cpp; \
    case "${TARGETARCH}" in \
        amd64) uwebsockets_arch=x64 ;; \
        arm64) uwebsockets_arch=arm64 ;; \
        *) echo "Unsupported uWebSockets architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    mkdir -p dist; \
    clang++ \
        -flto -O3 -pthread -std=c++20 -shared \
        -static-libstdc++ -static-libgcc -s \
        ./*.o -lssl -lcrypto -luv -lz \
        -o "dist/uws_linux_${uwebsockets_arch}_137.node"; \
    test -f "dist/uws_linux_${uwebsockets_arch}_137.node"; \
    git fetch --depth=1 origin "${UWEBSOCKETS_PACKAGE_COMMIT}"; \
    test "$(git rev-parse FETCH_HEAD)" = "${UWEBSOCKETS_PACKAGE_COMMIT}"; \
    test "$(git show "${UWEBSOCKETS_PACKAGE_COMMIT}:source_commit" | tr -d '\n')" = "${UWEBSOCKETS_SOURCE_COMMIT}"; \
    mkdir -p /out; \
    cp LICENSE /out/; \
    cp "dist/uws_linux_${uwebsockets_arch}_137.node" /out/; \
    git show "${UWEBSOCKETS_PACKAGE_COMMIT}:ESM_wrapper.mjs" > /out/ESM_wrapper.mjs; \
    git show "${UWEBSOCKETS_PACKAGE_COMMIT}:index.d.ts" > /out/index.d.ts; \
    git show "${UWEBSOCKETS_PACKAGE_COMMIT}:package.json" > /out/package.json; \
    git show "${UWEBSOCKETS_PACKAGE_COMMIT}:uws.js" > /out/uws.js; \
    node -e 'const fs = require("node:fs"); const pkg = JSON.parse(fs.readFileSync("/out/package.json", "utf8")); if (pkg.version !== process.argv[1]) process.exit(1)' "${UWEBSOCKETS_VERSION}"

FROM node-build-base AS soketi-builder

ARG SOKETI_VERSION
ARG SOKETI_COMMIT
ARG SOKETI_SOURCE_SHA256
ARG SOKETI_RUNTIME_AXIOS_VERSION
ARG SOKETI_RUNTIME_BASIC_FTP_VERSION
ARG SOKETI_RUNTIME_BRACES_VERSION
ARG SOKETI_RUNTIME_FOLLOW_REDIRECTS_VERSION
ARG SOKETI_RUNTIME_FORM_DATA_VERSION
ARG SOKETI_RUNTIME_LODASH_VERSION
ARG SOKETI_RUNTIME_MINIMATCH_VERSION
ARG SOKETI_RUNTIME_MSGPACKR_VERSION
ARG SOKETI_RUNTIME_MYSQL2_VERSION
ARG SOKETI_RUNTIME_PICOMATCH_VERSION
ARG SOKETI_RUNTIME_PM2_AGENT_VERSION
ARG SOKETI_RUNTIME_PM2_AGENT_WS_VERSION
ARG SOKETI_RUNTIME_PM2_VERSION
ARG SOKETI_RUNTIME_LOCK_SHA256
ARG UWEBSOCKETS_PACKAGE_COMMIT

WORKDIR /src/soketi

RUN set -eux; \
    curl --fail --location --silent --show-error \
        "https://github.com/soketi/soketi/archive/${SOKETI_COMMIT}.tar.gz" \
        --output /tmp/soketi.tar.gz; \
    echo "${SOKETI_SOURCE_SHA256}  /tmp/soketi.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/soketi.tar.gz --strip-components=1; \
    test -f package-lock.json

RUN --mount=type=cache,target=/root/.npm \
    set -eux; \
    npm ci --no-audit --no-fund; \
    npm run build

RUN --mount=type=cache,target=/root/.npm \
    set -eux; \
    npm pkg set \
        "version=${SOKETI_VERSION}" \
        "dependencies[axios]=${SOKETI_RUNTIME_AXIOS_VERSION}" \
        "dependencies[mysql2]=${SOKETI_RUNTIME_MYSQL2_VERSION}" \
        "dependencies[pm2]=${SOKETI_RUNTIME_PM2_VERSION}" \
        "dependencies[uWebSockets.js]=https://github.com/uNetworking/uWebSockets.js.git#${UWEBSOCKETS_PACKAGE_COMMIT}" \
        "peerDependencies[@pm2/agent]=${SOKETI_RUNTIME_PM2_AGENT_VERSION}" \
        "overrides[@pm2/agent][ws]=${SOKETI_RUNTIME_PM2_AGENT_WS_VERSION}" \
        "overrides[basic-ftp]=${SOKETI_RUNTIME_BASIC_FTP_VERSION}" \
        "overrides[braces]=${SOKETI_RUNTIME_BRACES_VERSION}" \
        "overrides[follow-redirects]=${SOKETI_RUNTIME_FOLLOW_REDIRECTS_VERSION}" \
        "overrides[form-data]=${SOKETI_RUNTIME_FORM_DATA_VERSION}" \
        "overrides[lodash]=${SOKETI_RUNTIME_LODASH_VERSION}" \
        "overrides[minimatch]=${SOKETI_RUNTIME_MINIMATCH_VERSION}" \
        "overrides[msgpackr]=${SOKETI_RUNTIME_MSGPACKR_VERSION}" \
        "overrides[picomatch]=${SOKETI_RUNTIME_PICOMATCH_VERSION}"; \
    npm pkg delete "dependencies[prometheus-query]"; \
    npm install --package-lock-only --ignore-scripts --no-audit --no-fund; \
    echo "${SOKETI_RUNTIME_LOCK_SHA256}  package-lock.json" | sha256sum -c -; \
    rm -rf node_modules; \
    npm ci --omit=dev --ignore-scripts --no-audit --no-fund; \
    test -f node_modules/resolve/test/resolver/multirepo/package.json; \
    rm -rf node_modules/resolve/test; \
    rm -rf node_modules/uWebSockets.js

COPY --from=uwebsockets-builder /out /src/soketi/node_modules/uWebSockets.js

RUN set -eux; \
    node -e 'const uWS = require("uWebSockets.js"); if (typeof uWS.App !== "function") process.exit(1)'; \
    mkdir -p /out; \
    cp -R bin dist node_modules LICENSE package.json package-lock.json README.md /out/

FROM node-build-base AS terminal-builder

WORKDIR /terminal

COPY docker/coolify-realtime/package*.json ./
RUN npm ci && npm rebuild node-pty

FROM node:${NODE_VERSION}

ARG SOKETI_VERSION

WORKDIR /terminal
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache curl libstdc++ libuv openssh-client openssl zlib; \
    rm -rf \
        /opt/yarn-v1.22.22 \
        /usr/local/lib/node_modules/corepack \
        /usr/local/lib/node_modules/npm; \
    rm -f \
        /usr/local/bin/corepack \
        /usr/local/bin/npm \
        /usr/local/bin/npx \
        /usr/local/bin/pnpm \
        /usr/local/bin/pnpx \
        /usr/local/bin/yarn \
        /usr/local/bin/yarnpkg

COPY --from=soketi-builder /out /app
COPY docker/coolify-realtime/package*.json ./
COPY --from=terminal-builder /terminal/node_modules /terminal/node_modules
COPY docker/coolify-realtime/soketi-entrypoint.sh /soketi-entrypoint.sh
COPY docker/coolify-realtime/terminal-server.js /terminal/terminal-server.js
COPY docker/coolify-realtime/terminal-utils.js /terminal/terminal-utils.js

COPY --from=cloudflared-builder --chmod=755 /out/cloudflared /usr/local/bin/cloudflared

LABEL org.opencontainers.image.authors="Soketi <open-source@soketi.app>" \
      org.opencontainers.image.documentation="https://docs.soketi.app" \
      org.opencontainers.image.licenses="AGPL-3.0" \
      org.opencontainers.image.source="https://github.com/soketi/soketi" \
      org.opencontainers.image.url="https://soketi.app" \
      org.opencontainers.image.vendor="Soketi" \
      org.opencontainers.image.version="${SOKETI_VERSION}"

EXPOSE 6001 6002

ENTRYPOINT ["/bin/sh", "/soketi-entrypoint.sh"]
