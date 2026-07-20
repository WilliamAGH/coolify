# syntax=docker/dockerfile:1@sha256:87999aa3d42bdc6bea60565083ee17e86d1f3339802f543c0d03998580f9cb89

# Versions
# https://hub.docker.com/r/serversideup/php/tags?name=8.5-fpm-nginx-alpine
ARG SERVERSIDEUP_PHP_VERSION=8.5-fpm-nginx-alpine@sha256:1854d81da23fc5c174a26bf039bc7724aeccec5743524717bbc6c10c1c927ac2
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
# https://www.postgresql.org/support/versioning/
# Note: We are using version 18 of the postgres client (while still using postgres 15 for the postgres server) as version 15 has been removed from Alpine 3.23+ https://pkgs.alpinelinux.org/packages?name=postgresql*-client&branch=v3.23&repo=&arch=x86_64&origin=&flagged=&maintainer=
ARG POSTGRES_VERSION=18
ARG SU_EXEC_VERSION=0.3-r0
# https://pkgs.alpinelinux.org/package/edge/community/x86_64/git-lfs
ARG GIT_LFS_VERSION=3.7.1-r1
ARG GIT_LFS_AMD64_SHA256=a43b0a41e8009d422d43806fe732c2b978e3ceec568394309251c1f611e098e6
ARG GIT_LFS_ARM64_SHA256=2bb47c493735a7681cf385303835c60ada86a811e107029e9bb3042d279ffa19
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
COPY . .
RUN npm run build

# =================================================================
# Stage 3: Cloudflared from verified vendored source
# =================================================================
FROM --platform=$BUILDPLATFORM ${CLOUDFLARED_GO_IMAGE} AS cloudflared-builder

ARG TARGETARCH
ARG CLOUDFLARED_VERSION
ARG CLOUDFLARED_COMMIT
ARG CLOUDFLARED_SOURCE_SHA256
ARG CLOUDFLARED_RELEASE_DATE

WORKDIR /src

RUN apk add --no-cache ca-certificates curl tar

RUN curl -fSsL "https://github.com/cloudflare/cloudflared/archive/${CLOUDFLARED_COMMIT}.tar.gz" -o /tmp/cloudflared.tar.gz && \
    echo "${CLOUDFLARED_SOURCE_SHA256}  /tmp/cloudflared.tar.gz" | sha256sum -c - && \
    tar -xzf /tmp/cloudflared.tar.gz --strip-components=1 && \
    test -f vendor/modules.txt

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

# =================================================================
# Final Stage: Production image
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION}

ARG USER_ID
ARG GROUP_ID
ARG POSTGRES_VERSION
ARG SU_EXEC_VERSION
ARG TARGETARCH
ARG GIT_LFS_VERSION
ARG GIT_LFS_AMD64_SHA256
ARG GIT_LFS_ARM64_SHA256
ARG NGINX_VERSION
ARG NGINX_APK_SIGNER_FILE_SHA256
ARG NGINX_APK_SIGNER_FINGERPRINT_SHA256
ARG CI=true

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

# Install only the exact patched Git LFS APK from Alpine edge without enabling its repository.
RUN set -eux; \
    case "${TARGETARCH}" in \
        amd64) GIT_LFS_APK_ARCH=x86_64; GIT_LFS_APK_SHA256="${GIT_LFS_AMD64_SHA256}" ;; \
        arm64) GIT_LFS_APK_ARCH=aarch64; GIT_LFS_APK_SHA256="${GIT_LFS_ARM64_SHA256}" ;; \
        *) echo "Unsupported TARGETARCH for Git LFS: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    GIT_LFS_APK="/tmp/git-lfs-${GIT_LFS_VERSION}.apk"; \
    curl -fSsL "https://dl-cdn.alpinelinux.org/alpine/edge/community/${GIT_LFS_APK_ARCH}/git-lfs-${GIT_LFS_VERSION}.apk" -o "${GIT_LFS_APK}"; \
    echo "${GIT_LFS_APK_SHA256}  ${GIT_LFS_APK}" | sha256sum -c -; \
    apk verify "${GIT_LFS_APK}"; \
    apk add --no-cache "${GIT_LFS_APK}"; \
    rm -f "${GIT_LFS_APK}"; \
    apk info -e "git-lfs=${GIT_LFS_VERSION}" >/dev/null; \
    GIT_LFS_RUNTIME_VERSION="$(git lfs version)"; \
    case "${GIT_LFS_RUNTIME_VERSION}" in \
        "git-lfs/${GIT_LFS_VERSION%-r*} "*) ;; \
        *) echo "Unexpected Git LFS runtime version: ${GIT_LFS_RUNTIME_VERSION}" >&2; exit 1 ;; \
    esac

# Install PHP extensions used by Artisan diagnostics and realtime features.
RUN install-php-extensions intl sockets

# Configure shell aliases
RUN echo "alias ll='ls -al'" >> /etc/profile && \
    echo "alias a='php artisan'" >> /etc/profile && \
    echo "alias logs='tail -f storage/logs/laravel.log'" >> /etc/profile

COPY --from=cloudflared-builder --chmod=755 /out/cloudflared /usr/local/bin/cloudflared

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
COPY --chown=www-data:www-data changelogs/ ./changelogs/
COPY --chown=www-data:www-data --chmod=755 scripts/control-plane-traefik-attestor ./scripts/control-plane-traefik-attestor

RUN composer dump-autoload

# Install terminal WebSocket server dependencies.
COPY docker/coolify-terminal/package*.json /terminal/
RUN apk add --no-cache --virtual .terminal-build-deps npm make g++ python3 && \
    npm ci --prefix /terminal && \
    npm rebuild node-pty --prefix /terminal --update-binary && \
    apk del .terminal-build-deps && \
    rm -rf /root/.npm
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
