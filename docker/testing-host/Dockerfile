# syntax=docker/dockerfile:1

# Pinned upstream build and runtime inputs. The companion provenance verifier
# proves these official tags resolve to these commits before CI builds an image.
ARG GO_BUILDER_IMAGE=golang:1.26.5-alpine@sha256:0178a641fbb4858c5f1b48e34bdaabe0350a330a1b1149aabd498d0699ff5fb2
ARG TESTING_HOST_BASE_IMAGE=alpine:3.24@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b
ARG BASH_VERSION=5.3.9-r1

# Docker 28.4.0 source tag: https://github.com/docker/cli/tree/v28.4.0
ARG DOCKER_VERSION=28.4.0
ARG DOCKER_CLI_TAG=v28.4.0
ARG DOCKER_CLI_COMMIT=d8eb465f86cfceeb57f8582e373d41a558d35503
ARG DOCKER_CLI_SOURCE_SHA256=16202845b8c8c019d406461b40e79a76c9a1b154325f9549093950499652621a

# Docker Compose v2.39.2 source tag: https://github.com/docker/compose/tree/v2.39.2
ARG DOCKER_COMPOSE_VERSION=2.39.2
ARG DOCKER_COMPOSE_TAG=v2.39.2
ARG DOCKER_COMPOSE_COMMIT=c2cb0aef6bbbe1afc8c9e81267621655ac90c5f6
ARG DOCKER_COMPOSE_SOURCE_SHA256=d6167591f51d5faee5ba899051fd8c0398454b2a23ea59377286dff28b9f90ee

# Docker Buildx v0.29.1 source tag: https://github.com/docker/buildx/tree/v0.29.1
ARG DOCKER_BUILDX_VERSION=0.29.1
ARG DOCKER_BUILDX_TAG=v0.29.1
ARG DOCKER_BUILDX_COMMIT=a32761aeb3debd39be1eca514af3693af0db334b
ARG DOCKER_BUILDX_SOURCE_SHA256=f38d7e6ecd9477528d9985bfa62d8f26524b6edfe706e1e03f6f60646d77f59b

FROM --platform=$BUILDPLATFORM ${GO_BUILDER_IMAGE} AS docker-cli-builder

ARG TARGETOS
ARG TARGETARCH
ARG DOCKER_VERSION
ARG DOCKER_CLI_TAG
ARG DOCKER_CLI_COMMIT
ARG DOCKER_CLI_SOURCE_SHA256

WORKDIR /go/src/github.com/docker/cli

RUN apk add --no-cache curl \
    && curl --fail --location --silent --show-error "https://github.com/docker/cli/archive/${DOCKER_CLI_COMMIT}.tar.gz" --output /tmp/docker-cli.tar.gz \
    && echo "${DOCKER_CLI_SOURCE_SHA256}  /tmp/docker-cli.tar.gz" | sha256sum -c - \
    && tar --extract --gzip --file /tmp/docker-cli.tar.gz --strip-components=1 \
    && GO111MODULE=off GOTOOLCHAIN=local CGO_ENABLED=0 GOOS="${TARGETOS}" GOARCH="${TARGETARCH}" \
        go build -buildvcs=false -trimpath -tags=grpcnotrace \
        -ldflags="-s -w -X github.com/docker/cli/cli/version.GitCommit=${DOCKER_CLI_COMMIT} -X github.com/docker/cli/cli/version.Version=${DOCKER_VERSION}" \
        -o /out/docker github.com/docker/cli/cmd/docker

FROM --platform=$BUILDPLATFORM ${GO_BUILDER_IMAGE} AS docker-compose-builder

ARG TARGETOS
ARG TARGETARCH
ARG DOCKER_COMPOSE_VERSION
ARG DOCKER_COMPOSE_TAG
ARG DOCKER_COMPOSE_COMMIT
ARG DOCKER_COMPOSE_SOURCE_SHA256

RUN apk add --no-cache curl git

WORKDIR /src

RUN curl --fail --location --silent --show-error "https://github.com/docker/compose/archive/${DOCKER_COMPOSE_COMMIT}.tar.gz" --output /tmp/docker-compose.tar.gz \
    && echo "${DOCKER_COMPOSE_SOURCE_SHA256}  /tmp/docker-compose.tar.gz" | sha256sum -c - \
    && tar --extract --gzip --file /tmp/docker-compose.tar.gz --strip-components=1 \
    && GOTOOLCHAIN=local GOPROXY=https://proxy.golang.org,direct GOSUMDB=sum.golang.org GOFLAGS=-mod=mod go mod download \
    && GOTOOLCHAIN=local GOPROXY=https://proxy.golang.org,direct GOSUMDB=sum.golang.org GOFLAGS=-mod=mod go mod verify \
    && GOTOOLCHAIN=local CGO_ENABLED=0 GOOS="${TARGETOS}" GOARCH="${TARGETARCH}" GOFLAGS=-mod=mod \
        go build -buildvcs=false -trimpath -tags=e2e \
        -ldflags="-s -w -X github.com/docker/compose/v2/internal.Version=v${DOCKER_COMPOSE_VERSION}" \
        -o /out/docker-compose ./cmd

FROM --platform=$BUILDPLATFORM ${GO_BUILDER_IMAGE} AS docker-buildx-builder

ARG TARGETOS
ARG TARGETARCH
ARG DOCKER_BUILDX_VERSION
ARG DOCKER_BUILDX_TAG
ARG DOCKER_BUILDX_COMMIT
ARG DOCKER_BUILDX_SOURCE_SHA256

RUN apk add --no-cache curl

WORKDIR /src

RUN curl --fail --location --silent --show-error "https://github.com/docker/buildx/archive/${DOCKER_BUILDX_COMMIT}.tar.gz" --output /tmp/docker-buildx.tar.gz \
    && echo "${DOCKER_BUILDX_SOURCE_SHA256}  /tmp/docker-buildx.tar.gz" | sha256sum -c - \
    && tar --extract --gzip --file /tmp/docker-buildx.tar.gz --strip-components=1 \
    && test -f vendor/modules.txt \
    && GOTOOLCHAIN=local GOPROXY=off CGO_ENABLED=0 GOOS="${TARGETOS}" GOARCH="${TARGETARCH}" GOFLAGS=-mod=vendor \
        go build -buildvcs=false -trimpath \
        -ldflags="-s -w -X github.com/docker/buildx/version.Package=github.com/docker/buildx -X github.com/docker/buildx/version.Version=v${DOCKER_BUILDX_VERSION} -X github.com/docker/buildx/version.Revision=${DOCKER_BUILDX_COMMIT}" \
        -o /out/docker-buildx ./cmd/buildx

FROM ${TESTING_HOST_BASE_IMAGE}

ARG BASH_VERSION
USER root
WORKDIR /root
ENV PATH="/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin:$PATH"

# Bash is an SSH execution contract and is pinned to the exact package revision
# in the digest-pinned Alpine 3.24 repository. Other packages intentionally
# resolve to security-current revisions; CI attests the resulting image digest.
RUN apk upgrade --no-cache \
    && apk add --no-cache "bash=${BASH_VERSION}" ca-certificates openssh-client openssh-server curl git jq jc \
    && apk info -e "bash=${BASH_VERSION}" >/dev/null \
    && bash -c 'test "$BASH_VERSION" = "${0%-r*}(1)-release"' "$BASH_VERSION" \
    && rm -f /etc/ssh/ssh_host_*_key /etc/ssh/ssh_host_*_key.pub \
    && mkdir -p /run/sshd /root/.docker/cli-plugins

COPY --from=docker-cli-builder --chmod=755 /out/docker /usr/local/bin/docker
COPY --from=docker-compose-builder --chmod=755 /out/docker-compose /root/.docker/cli-plugins/docker-compose
COPY --from=docker-buildx-builder --chmod=755 /out/docker-buildx /root/.docker/cli-plugins/docker-buildx

COPY docker/testing-host/keygen.sh /usr/local/bin/coolify-testing-host-keygen
COPY docker/testing-host/entrypoint.sh /usr/local/bin/coolify-testing-host-entrypoint
RUN chmod 755 /usr/local/bin/coolify-testing-host-keygen /usr/local/bin/coolify-testing-host-entrypoint

EXPOSE 22
CMD ["/bin/sh", "/usr/local/bin/coolify-testing-host-entrypoint"]
