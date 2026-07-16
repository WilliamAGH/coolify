#!/bin/sh

set -eu

fail()
{
    printf 'IMAGE_SOURCE_PROVENANCE_FAILURE %s\n' "$1" >&2
    exit 1
}

[ "$#" -eq 1 ] || fail 'expected one Dockerfile path'
dockerfile=$1
test -f "$dockerfile" || fail "Dockerfile is absent: $dockerfile"

dockerfile_frontend=$(sed -n 's/^# syntax=//p' "$dockerfile" | sed -n '1p')
[ "$dockerfile_frontend" = 'docker/dockerfile:1@sha256:87999aa3d42bdc6bea60565083ee17e86d1f3339802f543c0d03998580f9cb89' ] ||
    fail 'Dockerfile frontend is not pinned to the reviewed multi-platform digest'

argument_value()
{
    name=$1
    value=$(sed -n "s/^ARG $name=//p" "$dockerfile" | sed -n '1p')
    [ -n "$value" ] || fail "missing Dockerfile argument: $name"
    printf '%s\n' "$value"
}

assert_version_tag()
{
    version=$1
    tag=$2
    prefix=$3
    [ "$tag" = "$prefix$version" ] || fail "version $version does not match tag $tag"
}

assert_official_tag_commit()
{
    repository=$1
    tag=$2
    expected_commit=$3
    tag_reference="refs/tags/$tag"
    actual_commit=$(
        git ls-remote --tags "$repository" "$tag_reference" "$tag_reference^{}" |
            awk -v tag_reference="$tag_reference" '
                $2 == tag_reference { direct = $1 }
                $2 == tag_reference "^{}" { peeled = $1 }
                END {
                    if (peeled != "") {
                        print peeled
                    } else {
                        print direct
                    }
                }
            '
    )

    [ -n "$actual_commit" ] || fail "official tag is absent: $repository $tag"
    [ "$actual_commit" = "$expected_commit" ] ||
        fail "official tag $tag resolves to $actual_commit, not $expected_commit"
}

assert_runtime_contract_source()
{
    source_path=$1

    test -f "$source_path" || fail "runtime contract source is absent: $source_path"
    source_sha256=$(sha256sum "$source_path" | awk '{print $1}')
    printf 'IMAGE_SOURCE_PROVENANCE runtime_contract_source=%s sha256=%s\n' \
        "$source_path" "$source_sha256"
}

case "$dockerfile" in
    docker/testing-host/Dockerfile)
        docker_version=$(argument_value DOCKER_VERSION)
        docker_cli_tag=$(argument_value DOCKER_CLI_TAG)
        docker_cli_commit=$(argument_value DOCKER_CLI_COMMIT)
        compose_version=$(argument_value DOCKER_COMPOSE_VERSION)
        compose_tag=$(argument_value DOCKER_COMPOSE_TAG)
        compose_commit=$(argument_value DOCKER_COMPOSE_COMMIT)
        buildx_version=$(argument_value DOCKER_BUILDX_VERSION)
        buildx_tag=$(argument_value DOCKER_BUILDX_TAG)
        buildx_commit=$(argument_value DOCKER_BUILDX_COMMIT)

        assert_version_tag "$docker_version" "$docker_cli_tag" v
        assert_version_tag "$compose_version" "$compose_tag" v
        assert_version_tag "$buildx_version" "$buildx_tag" v
        assert_official_tag_commit 'https://github.com/docker/cli.git' "$docker_cli_tag" "$docker_cli_commit"
        assert_official_tag_commit 'https://github.com/docker/compose.git' "$compose_tag" "$compose_commit"
        assert_official_tag_commit 'https://github.com/docker/buildx.git' "$buildx_tag" "$buildx_commit"

        for source_path in \
            docker/verify-source-provenance.sh \
            docker/testing-host/entrypoint.sh \
            docker/testing-host/keygen.sh \
            tests/Integration/VerifyOciArchiveImage.sh \
            tests/Integration/TestingHostImageTest.sh
        do
            assert_runtime_contract_source "$source_path"
        done

        printf 'IMAGE_SOURCE_PROVENANCE verified docker=%s compose=%s buildx=%s\n' \
            "$docker_version" "$compose_version" "$buildx_version"
        ;;
    docker/production/Dockerfile)
        cloudflared_version=$(argument_value CLOUDFLARED_VERSION)
        cloudflared_tag=$(argument_value CLOUDFLARED_TAG)
        cloudflared_commit=$(argument_value CLOUDFLARED_COMMIT)

        assert_version_tag "$cloudflared_version" "$cloudflared_tag" ''
        assert_official_tag_commit \
            'https://github.com/cloudflare/cloudflared.git' \
            "$cloudflared_tag" \
            "$cloudflared_commit"

        for source_path in \
            docker/verify-source-provenance.sh \
            docker-compose.preflight.yml \
            tests/Integration/VerifyOciArchiveImage.sh \
            tests/Integration/PassiveControlPlaneImageTest.sh
        do
            assert_runtime_contract_source "$source_path"
        done

        printf 'IMAGE_SOURCE_PROVENANCE verified cloudflared=%s\n' "$cloudflared_version"
        ;;
    *)
        fail "unsupported Dockerfile: $dockerfile"
        ;;
esac
