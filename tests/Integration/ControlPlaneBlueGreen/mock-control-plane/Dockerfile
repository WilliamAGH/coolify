# syntax=docker/dockerfile:1

FROM alpine:3.24@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b

RUN apk add --no-cache bash busybox-extras coreutils curl docker-cli php85 php85-ctype \
        php85-mbstring php85-openssl php85-pcntl php85-pdo_pgsql php85-posix php85-session \
        php85-tokenizer \
        postgresql-client python3 su-exec=0.3-r0 util-linux \
    && addgroup -g 9999 controlplane \
    && adduser -D -H -u 9999 -G controlplane controlplane \
    && mkdir -p /var/lib/coolify-control-plane /lab-state /srv/www/cgi-bin \
    && chown -R 9999:9999 /var/lib/coolify-control-plane /lab-state /srv/www

COPY --chmod=755 entrypoint.sh /usr/local/bin/coolify-entrypoint
COPY --chmod=755 control-plane-direct-probe-healthcheck /usr/local/bin/control-plane-direct-probe-healthcheck
COPY --chmod=755 rehearse-migration.sh /usr/local/bin/rehearse-migration
COPY run-migrations.php /usr/local/bin/run-control-plane-migrations.php
COPY --chmod=755 probe.sh /srv/www/cgi-bin/probe
COPY --chmod=755 request.sh /srv/www/cgi-bin/request
COPY --chmod=755 route-health.sh /srv/www/cgi-bin/route-health
COPY --chmod=755 http-server.py /usr/local/bin/control-plane-lab-http-server
COPY --chmod=755 lab-service.sh /usr/local/bin/control-plane-lab-service
COPY --chmod=755 dependency.sh /usr/local/bin/control-plane-lab-dependency
COPY --chmod=755 systemctl /usr/local/bin/systemctl
COPY --chmod=755 install /usr/local/bin/install
COPY --chmod=755 ssh /usr/local/bin/ssh
COPY health /srv/www/api/health
COPY config/database.php /var/www/html/config/database.php
COPY app/Support/ControlPlaneMigrationInventory.php /var/www/html/app/Support/ControlPlaneMigrationInventory.php
COPY migrations /var/www/html/database/migrations
COPY vendor/composer /var/www/html/vendor/composer
COPY vendor/doctrine/inflector /var/www/html/vendor/doctrine/inflector
COPY vendor/laravel/framework /var/www/html/vendor/laravel/framework
COPY vendor/psr/container /var/www/html/vendor/psr/container
COPY vendor/symfony/polyfill-php85 /var/www/html/vendor/symfony/polyfill-php85

WORKDIR /var/www/html

USER 9999:9999

ENTRYPOINT ["/usr/local/bin/coolify-entrypoint"]
