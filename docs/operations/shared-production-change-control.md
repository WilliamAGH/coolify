# Shared Production Change Control

This document is the repository owner for deciding whether release work may change external production infrastructure. It applies to Nexus, Docker registries, Coolify, DNS, GitHub organization and repository rulesets, Actions runner groups, and host daemon configuration.

## Decision contract

| Situation | Required action |
|---|---|
| Read live configuration or health | Use read-only APIs and record the exact observed value. |
| Repository behavior conflicts with live shared policy | Change and test the repository-owned workflow, code, or configuration. |
| The repository cannot operate without an external mutation | Report `BLOCKED` with the exact target, current value, requested value, consumers, blast radius, rollback, and verification. |
| The user explicitly authorizes that exact mutation | Re-read the target, apply only the named field change, verify it, and retain the rollback value. |
| The user objects, interrupts, or withdraws authorization | Stop new writes; revert only mutations made by the current session; cancel only runs started by the current session; verify the prior state. |

Broad completion requests do not authorize an external mutation. Authorization must name the external target and exact change after the blast radius and rollback are visible.

## Coolify fork publication contract

The shared Nexus `docker-hosted` repository is an existing multi-consumer production service with `storage.writePolicy=ALLOW`. Fork publication treats that value as fixed input and must not toggle it to `ALLOW_ONCE` or `DENY`.

The repository owns release tag and digest safety using the same tag train as `hybrid/back-end/scripts/ci/deriveImageTags.mjs`:

1. Fork staging tags are unique to the workflow run and disposable.
2. The protected semantic compatibility tag is accepted only when absent or already bound to the exact expected OCI index digest.
3. Canonical publication writes `fork-latest`, `fork-<version>`, and `fork-<version>-<short-sha>`, then verifies that all three resolve to the built index digest.
4. `fork-latest` is the intentionally mutable channel; the version and hash tags retain release and source identity.
5. OCI labels, platform digests, SBOMs, provenance, signatures, the protected Git tag, and the GitHub Release are bound to the same source revision and index digest; deployment consumes `image@sha256:<digest>`.

Tag preflight and post-write verification are observational rather than an atomic compare-and-set under `ALLOW`. Signed bundles and deployments therefore consume `repository@sha256:<digest>` as the security boundary; canonical tags are discovery and channel pointers.

If registry-enforced first-write immutability becomes mandatory, design a dedicated Coolify hosted repository and routing path. Creating it is a separately authorized infrastructure change; it is not a release workaround.

## External surfaces that remain read-only during release repair

- Nexus repository configuration and security realms
- Docker registry routing, authentication, and shared credentials
- Coolify services, proxy configuration, and environment variables
- DNS records and certificates
- GitHub rulesets, runner groups, and runner registration
- Host Docker, systemd, firewall, and network configuration

The workflow may read these surfaces for preflight and verification. A failed read or mismatched value fails closed and routes back to the decision contract above.
