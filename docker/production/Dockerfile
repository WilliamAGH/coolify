# syntax=docker/dockerfile:1@sha256:87999aa3d42bdc6bea60565083ee17e86d1f3339802f543c0d03998580f9cb89

# Versions
# https://hub.docker.com/r/serversideup/php/tags?name=8.5-fpm-nginx-alpine
ARG SERVERSIDEUP_PHP_VERSION=8.5-fpm-nginx-alpine@sha256:638a31d2201022b61605fd423b86ccaed20c12732f6aba4870f4c3ff1d8e57da
# https://hub.docker.com/_/node
ARG NODE_VERSION=24-alpine@sha256:a0b9bf06e4e6193cf7a0f58816cc935ff8c2a908f81e6f1a95432d679c54fbfd
# https://hub.docker.com/_/golang
ARG GO_BUILD_IMAGE=golang:1.26.5-alpine@sha256:0178a641fbb4858c5f1b48e34bdaabe0350a330a1b1149aabd498d0699ff5fb2
# https://github.com/git-lfs/git-lfs/releases/tag/v3.7.1
ARG GIT_LFS_VERSION=3.7.1
ARG GIT_LFS_TAG=v3.7.1
ARG GIT_LFS_COMMIT=b84b33847fe6458f36ef521534dc0eac953cb379
ARG GIT_LFS_SOURCE_SHA256=e1ef5ba4828fa632337be6a2c421a685432faec0405bf187f46a1459e82a9a62
# https://pkg.go.dev/vuln/GO-2026-5026
ARG GIT_LFS_X_NET_VERSION=v0.56.0
# https://pkg.go.dev/vuln/GO-2026-5970
ARG GIT_LFS_X_TEXT_VERSION=v0.39.0
ARG GIT_LFS_GO_MOD_SHA256=9fd88c4c8e3c1c3f2ab5d8449c9ce6ec8f9c21124a7a33e3372654f45a5a5be0
ARG GIT_LFS_GO_SUM_SHA256=709461d5999a5fb0e1acf4d205dcefdd249b8315a8c1a81344b8eefd90f596ee
# https://github.com/cloudflare/cloudflared/releases/tag/2026.7.1
ARG CLOUDFLARED_VERSION=2026.7.1
ARG CLOUDFLARED_TAG=2026.7.1
# The 2026.7.1 annotated tag resolves to this immutable source commit.
ARG CLOUDFLARED_COMMIT=ecb88678f1368581964ca2bb55615502a2ed2dbe
ARG CLOUDFLARED_SOURCE_SHA256=94a8f8e057bf4eec41416d6db56a5f8379b833fd40fdce4dcdc668b8cb6b5c65
ARG CLOUDFLARED_RELEASE_DATE=2026-07-09-12:06
# https://github.com/advisories/GHSA-hrxh-6v49-42gf
ARG CLOUDFLARED_GRPC_VERSION=v1.82.1
# https://pkg.go.dev/vuln/GO-2026-5942
ARG CLOUDFLARED_X_NET_VERSION=v0.56.0
# https://pkg.go.dev/vuln/GO-2026-5970
ARG CLOUDFLARED_X_TEXT_VERSION=v0.39.0
ARG CLOUDFLARED_GO_MOD_SHA256=e0f8109466e24fe9cb240c700025acdc4ec16b054702ea95f7f84e6a746120b1
ARG CLOUDFLARED_GO_SUM_SHA256=15826bd98600e4f7ebbe7f4042145037fb40a2e9739251a388eb6e46ca6c5c52
ARG CLOUDFLARED_VENDOR_MODULES_SHA256=a275b5c08eb99e8d9c4c3e877add4f73a2a716306420a7e90fd704411b43a5de
# https://www.postgresql.org/support/versioning/
# Note: We are using version 18 of the postgres client (while still using postgres 15 for the postgres server) as version 15 has been removed from Alpine 3.23+ https://pkgs.alpinelinux.org/packages?name=postgresql*-client&branch=v3.23&repo=&arch=x86_64&origin=&flagged=&maintainer=
ARG POSTGRES_VERSION=18
ARG SU_EXEC_VERSION=0.3-r0
# https://nginx.org/en/linux_packages.html
ARG NGINX_VERSION=1.31.2-r1
ARG NGINX_APK_SIGNER_FILE_SHA256=ff7cf138acc09f2a5029300ab713fe6a1440605fca72e2bab76a4da9206fec87
ARG NGINX_APK_SIGNER_FINGERPRINT_SHA256=03833138bf6288dcb545f7a154af24f5c63b94931fef6b078cd8061204a44327

# Add user/group
ARG USER_ID=9999
ARG GROUP_ID=9999

# =================================================================
# Stage 1: Composer dependencies
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION} AS base

USER root

# Install patched Nginx from the official nginx.org Alpine repository
ARG NGINX_VERSION
ARG NGINX_APK_SIGNER_FILE_SHA256
ARG NGINX_APK_SIGNER_FINGERPRINT_SHA256
RUN set -eux; \
    apk add --no-cache ca-certificates curl openssl; \
    NGINX_ALPINE_VERSION="$(egrep -o '^[0-9]+\.[0-9]+' /etc/alpine-release)"; \
    NGINX_REPOSITORY="https://nginx.org/packages/mainline/alpine/v${NGINX_ALPINE_VERSION}/main"; \
    sed -i 's|http://nginx.org/packages|https://nginx.org/packages|g' /etc/apk/repositories; \
    grep -qxF "@nginx ${NGINX_REPOSITORY}" /etc/apk/repositories || echo "@nginx ${NGINX_REPOSITORY}" >> /etc/apk/repositories; \
    curl -fsSL https://nginx.org/keys/nginx_signing.rsa.pub -o /tmp/nginx_signing.rsa.pub; \
    echo "${NGINX_APK_SIGNER_FILE_SHA256}  /tmp/nginx_signing.rsa.pub" | sha256sum -c -; \
    NGINX_APK_SIGNER_FINGERPRINT_ACTUAL="$(openssl rsa -pubin -in /tmp/nginx_signing.rsa.pub -outform DER 2>/dev/null | sha256sum | awk '{print $1}')"; \
    test "${NGINX_APK_SIGNER_FINGERPRINT_ACTUAL}" = "${NGINX_APK_SIGNER_FINGERPRINT_SHA256}"; \
    mv /tmp/nginx_signing.rsa.pub /etc/apk/keys/nginx_signing.rsa.pub; \
    apk add --no-cache --upgrade "nginx@nginx=${NGINX_VERSION}"; \
    rm -f /etc/nginx/nginx.conf /etc/nginx/conf.d/default.conf; \
    nginx -v

ARG USER_ID
ARG GROUP_ID

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/cache \
    COMPOSER_CACHE_DIR=/tmp/cache composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist

USER www-data

# =================================================================
# Stage 2: Frontend assets compilation
# =================================================================
FROM node:${NODE_VERSION} AS static-assets

WORKDIR /app
COPY package*.json vite.config.js postcss.config.cjs ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci
COPY app ./app
COPY config ./config
COPY lang ./lang
COPY resources ./resources
COPY routes ./routes
COPY templates ./templates
RUN npm run build

# =================================================================
# Stage 3: Terminal runtime dependencies
# =================================================================
FROM node:${NODE_VERSION} AS terminal-assets

WORKDIR /terminal
COPY docker/coolify-terminal/package*.json ./
RUN apk add --no-cache make g++ python3 && \
    npm ci && \
    npm rebuild node-pty && \
    rm -rf /root/.npm

# =================================================================
# Stage 4: Pinned Go source-build base
# =================================================================
FROM --platform=$BUILDPLATFORM ${GO_BUILD_IMAGE} AS go-source-builder

WORKDIR /src

RUN apk add --no-cache ca-certificates curl tar

# =================================================================
# Stage 5: Cloudflared from verified vendored source
# =================================================================
FROM go-source-builder AS cloudflared-builder

ARG TARGETARCH
ARG CLOUDFLARED_VERSION
ARG CLOUDFLARED_COMMIT
ARG CLOUDFLARED_SOURCE_SHA256
ARG CLOUDFLARED_RELEASE_DATE
ARG CLOUDFLARED_GRPC_VERSION
ARG CLOUDFLARED_X_NET_VERSION
ARG CLOUDFLARED_X_TEXT_VERSION
ARG CLOUDFLARED_GO_MOD_SHA256
ARG CLOUDFLARED_GO_SUM_SHA256
ARG CLOUDFLARED_VENDOR_MODULES_SHA256

ENV GOTOOLCHAIN=local \
    GOPROXY=https://proxy.golang.org \
    GOSUMDB=sum.golang.org

RUN curl -fSsL "https://github.com/cloudflare/cloudflared/archive/${CLOUDFLARED_COMMIT}.tar.gz" -o /tmp/cloudflared.tar.gz && \
    echo "${CLOUDFLARED_SOURCE_SHA256}  /tmp/cloudflared.tar.gz" | sha256sum -c - && \
    tar -xzf /tmp/cloudflared.tar.gz --strip-components=1 && \
    test -f vendor/modules.txt

RUN --mount=type=cache,target=/go/pkg/mod \
    go get "google.golang.org/grpc@${CLOUDFLARED_GRPC_VERSION}" "golang.org/x/net@${CLOUDFLARED_X_NET_VERSION}" "golang.org/x/text@${CLOUDFLARED_X_TEXT_VERSION}" && \
    echo "${CLOUDFLARED_GO_MOD_SHA256}  go.mod" | sha256sum -c - && \
    echo "${CLOUDFLARED_GO_SUM_SHA256}  go.sum" | sha256sum -c - && \
    go mod download && \
    go mod verify && \
    go mod vendor && \
    echo "${CLOUDFLARED_VENDOR_MODULES_SHA256}  vendor/modules.txt" | sha256sum -c - && \
    test "$(go env GOVERSION)" = 'go1.26.5'

# Cloudflare's Makefile builds with GOOS/GOARCH, -mod=vendor, and these version fields.
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

RUN go version -m /out/cloudflared | awk -v expected="${CLOUDFLARED_GRPC_VERSION}" '$1 == "dep" && $2 == "google.golang.org/grpc" && $3 == expected { found = 1 } END { exit found ? 0 : 1 }' && \
    go version -m /out/cloudflared | awk -v expected="${CLOUDFLARED_X_NET_VERSION}" '$1 == "dep" && $2 == "golang.org/x/net" && $3 == expected { found = 1 } END { exit found ? 0 : 1 }' && \
    go version -m /out/cloudflared | awk -v expected="${CLOUDFLARED_X_TEXT_VERSION}" '$1 == "dep" && $2 == "golang.org/x/text" && $3 == expected { found = 1 } END { exit found ? 0 : 1 }'

# =================================================================
# Stage 6: Git LFS from checksum-verified source and Go modules
# =================================================================
FROM go-source-builder AS git-lfs-builder

ARG TARGETARCH
ARG GIT_LFS_VERSION
ARG GIT_LFS_COMMIT
ARG GIT_LFS_SOURCE_SHA256
ARG GIT_LFS_X_NET_VERSION
ARG GIT_LFS_X_TEXT_VERSION
ARG GIT_LFS_GO_MOD_SHA256
ARG GIT_LFS_GO_SUM_SHA256

ENV GOTOOLCHAIN=local \
    GOPROXY=https://proxy.golang.org \
    GOSUMDB=sum.golang.org

RUN curl -fSsL "https://github.com/git-lfs/git-lfs/archive/${GIT_LFS_COMMIT}.tar.gz" -o /tmp/git-lfs.tar.gz && \
    echo "${GIT_LFS_SOURCE_SHA256}  /tmp/git-lfs.tar.gz" | sha256sum -c - && \
    tar -xzf /tmp/git-lfs.tar.gz --strip-components=1 && \
    test -f go.mod && \
    test -f go.sum

RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    go get "golang.org/x/net@${GIT_LFS_X_NET_VERSION}" "golang.org/x/text@${GIT_LFS_X_TEXT_VERSION}" && \
    go mod tidy && \
    echo "${GIT_LFS_GO_MOD_SHA256}  go.mod" | sha256sum -c - && \
    echo "${GIT_LFS_GO_SUM_SHA256}  go.sum" | sha256sum -c - && \
    go mod download && \
    go mod verify && \
    test "$(go env GOVERSION)" = 'go1.26.5' && \
    test "$(awk '$1 == "golang.org/x/net" { print $2 }' go.mod)" = "${GIT_LFS_X_NET_VERSION}" && \
    test "$(awk '$1 == "golang.org/x/text" { print $2 }' go.mod)" = "${GIT_LFS_X_TEXT_VERSION}" && \
    GOOS=linux \
    GOARCH="${TARGETARCH}" \
    CGO_ENABLED=0 \
    go build \
        -mod=readonly \
        -trimpath \
        -buildvcs=false \
        -ldflags="-s -w -X github.com/git-lfs/git-lfs/v3/config.GitCommit=${GIT_LFS_COMMIT}" \
        -o /out/git-lfs \
        ./git-lfs.go

RUN /out/git-lfs version | grep -E "^git-lfs/${GIT_LFS_VERSION} \\(GitHub; linux ${TARGETARCH}; go 1\\.26\\.5; git ${GIT_LFS_COMMIT}\\)$" && \
    go version -m /out/git-lfs | awk -v expected="${GIT_LFS_X_NET_VERSION}" '$1 == "dep" && $2 == "golang.org/x/net" && $3 == expected { found = 1 } END { exit found ? 0 : 1 }' && \
    go version -m /out/git-lfs | awk -v expected="${GIT_LFS_X_TEXT_VERSION}" '$1 == "dep" && $2 == "golang.org/x/text" && $3 == expected { found = 1 } END { exit found ? 0 : 1 }'

# =================================================================
# Final Stage: Production image
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION}

ARG TARGETARCH
ARG USER_ID
ARG GROUP_ID
ARG POSTGRES_VERSION
ARG SU_EXEC_VERSION
ARG GIT_LFS_VERSION
ARG GIT_LFS_COMMIT
ARG CLOUDFLARED_VERSION
ARG NGINX_VERSION
ARG NGINX_APK_SIGNER_FILE_SHA256
ARG NGINX_APK_SIGNER_FINGERPRINT_SHA256
ARG CI=true

STOPSIGNAL SIGTERM

WORKDIR /var/www/html

USER root

# Install patched Nginx from the official nginx.org Alpine repository
RUN set -eux; \
    apk add --no-cache ca-certificates curl openssl; \
    NGINX_ALPINE_VERSION="$(egrep -o '^[0-9]+\.[0-9]+' /etc/alpine-release)"; \
    NGINX_REPOSITORY="https://nginx.org/packages/mainline/alpine/v${NGINX_ALPINE_VERSION}/main"; \
    sed -i 's|http://nginx.org/packages|https://nginx.org/packages|g' /etc/apk/repositories; \
    grep -qxF "@nginx ${NGINX_REPOSITORY}" /etc/apk/repositories || echo "@nginx ${NGINX_REPOSITORY}" >> /etc/apk/repositories; \
    curl -fsSL https://nginx.org/keys/nginx_signing.rsa.pub -o /tmp/nginx_signing.rsa.pub; \
    echo "${NGINX_APK_SIGNER_FILE_SHA256}  /tmp/nginx_signing.rsa.pub" | sha256sum -c -; \
    NGINX_APK_SIGNER_FINGERPRINT_ACTUAL="$(openssl rsa -pubin -in /tmp/nginx_signing.rsa.pub -outform DER 2>/dev/null | sha256sum | awk '{print $1}')"; \
    test "${NGINX_APK_SIGNER_FINGERPRINT_ACTUAL}" = "${NGINX_APK_SIGNER_FINGERPRINT_SHA256}"; \
    mv /tmp/nginx_signing.rsa.pub /etc/apk/keys/nginx_signing.rsa.pub; \
    apk add --no-cache --upgrade "nginx@nginx=${NGINX_VERSION}"; \
    rm -f /etc/nginx/nginx.conf /etc/nginx/conf.d/default.conf; \
    nginx -v

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# Install system dependencies
RUN --mount=type=cache,target=/var/cache/apk \
    apk upgrade && \
    apk add --no-cache \
    postgresql${POSTGRES_VERSION}-client \
    nodejs \
    "su-exec=${SU_EXEC_VERSION}" \
    openssh-client \
    git \
    jq \
    lsof \
    util-linux-misc \
    vim && \
    apk info -e "su-exec=${SU_EXEC_VERSION}" >/dev/null

# Install PHP extensions used by Artisan diagnostics and realtime features.
RUN install-php-extensions intl sockets

# Configure shell aliases
RUN echo "alias ll='ls -al'" >> /etc/profile && \
    echo "alias a='php artisan'" >> /etc/profile && \
    echo "alias logs='tail -f storage/logs/laravel.log'" >> /etc/profile

COPY --from=git-lfs-builder --chmod=755 /out/git-lfs /usr/local/bin/git-lfs
COPY --from=cloudflared-builder --chmod=755 /out/cloudflared /usr/local/bin/cloudflared

RUN test "$(command -v git-lfs)" = /usr/local/bin/git-lfs && \
    ! apk info -e git-lfs >/dev/null 2>&1 && \
    git lfs version | grep -E "^git-lfs/${GIT_LFS_VERSION} \\(GitHub; linux ${TARGETARCH}; go 1\\.26\\.5; git ${GIT_LFS_COMMIT}\\)$" && \
    test "$(command -v cloudflared)" = /usr/local/bin/cloudflared && \
    cloudflared --version | grep -F "cloudflared version ${CLOUDFLARED_VERSION}"

# Configure PHP
COPY docker/production/etc/php/conf.d/zzz-custom-php.ini /usr/local/etc/php/conf.d/zzz-custom-php.ini
ENV PHP_OPCACHE_ENABLE=1

# Configure entrypoint
COPY --chmod=755 docker/production/entrypoint.d/ /etc/entrypoint.d

# Copy application files from previous stages
COPY --from=base --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=static-assets --chown=www-data:www-data /app/public/build ./public/build

# Copy application source code
COPY --chown=www-data:www-data composer.json composer.lock ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database/migrations ./database/migrations
COPY --chown=www-data:www-data \
    database/seeders/CaSslCertSeeder.php \
    database/seeders/DatabaseSeeder.php \
    database/seeders/OauthSettingSeeder.php \
    database/seeders/PopulateSshKeysDirectorySeeder.php \
    database/seeders/ProductionSeeder.php \
    database/seeders/RootUserSeeder.php \
    database/seeders/SentinelSeeder.php \
    ./database/seeders/
COPY --chown=www-data:www-data lang ./lang
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data storage ./storage
COPY --chown=www-data:www-data templates ./templates
COPY --chown=www-data:www-data resources/views ./resources/views
COPY --chown=www-data:www-data artisan artisan
COPY --chown=www-data:www-data openapi.yaml ./openapi.yaml
COPY --chown=www-data:www-data versions.json ./versions.json
COPY --chown=www-data:www-data changelogs/ ./changelogs/
COPY --chown=www-data:www-data --chmod=755 scripts/control-plane-traefik-attestor ./scripts/control-plane-traefik-attestor

RUN composer dump-autoload

# Install terminal WebSocket server dependencies.
COPY --from=terminal-assets /terminal/node_modules /terminal/node_modules
COPY --from=terminal-assets /terminal/package*.json /terminal/
COPY docker/coolify-terminal/terminal-server.js /terminal/terminal-server.js
COPY docker/coolify-terminal/terminal-utils.js /terminal/terminal-utils.js
RUN chown -R www-data:www-data /terminal

# Configure Nginx and S6 overlay
COPY docker/production/etc/nginx/conf.d/custom.conf /etc/nginx/conf.d/custom.conf
COPY docker/production/etc/nginx/site-opts.d/http.conf /etc/nginx/site-opts.d/http.conf
COPY --chmod=755 docker/production/etc/s6-overlay/ /etc/s6-overlay/

RUN mkdir -p /etc/nginx/conf.d && \
    chown -R www-data:www-data /etc/nginx && \
    chmod -R 755 /etc/nginx && \
    install -d -m 0700 -o www-data -g www-data /var/www/html/storage/app/ssh

# Switch to non-root user
USER www-data

CMD ["/init"]
