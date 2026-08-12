# popos-sf0 Coolify release staging recovery

## Scope and verified state

As verified on 2026-08-11, `22.haiku.host` remains the one canonical
production control-plane host, served at `https://coolify.haiku.host`.
`popos-sf0` is being converted in place into the Coolify fork's dev and
release-staging endpoint at `https://dev.coolify.haiku.host`.

This is an ingress and control-plane-state recovery, not a fresh installation,
database rebuild, or production takeover. Do not destroy sf0, wipe its state,
or infer that copied records mean a workload was missed during migration. The
2026-08-11 audit found copied production rows to be metadata residue: there
was no evidence of an sf0-only active server, destination container, managed
route, service, or database that failed to migrate to the canonical plane.

The DNS A record for `dev.coolify.haiku.host` points at sf0's public address
(`75.8.210.180`). DNS is not the current fault. The existing Coolify app is
healthy on its direct host port 8000; sf0 lacked a working 80/443 ingress.

## Cause of the failed ingress

sf0 retained a copied `control_plane_proxy_enrollment` in the `enrolled`
phase for the production control plane. That stale enrollment made the proxy
Compose configuration publish host port 8000 and add Traefik's `coolify`
entrypoint on port 8000. The already-running sf0 `coolify` app also owns host
port 8000. Consequently, the never-started `coolify-proxy` container remained
in `Created` state and failed with a host-port collision.

This mixed state also prevents the ordinary dynamic-proxy owner from doing its
job: while a non-rolled-back enrollment exists, `Server::setupDynamicProxyConfiguration()`
does not generate the normal instance route. The recovery is therefore to
fence and clear the copied enrollment state, restore the ordinary proxy shape,
and let that owner generate the staging route. It is not to move the app off
8000, add a second proxy, hand-write a parallel route, or reactivate the
production enrollment.

## Required boundaries

- Do not change, seed, stop, fail over, or route traffic to
  `22.haiku.host` as part of this work. Its production hostname is
  `coolify.haiku.host`.
- Do not delete or selectively sanitize sf0's copied database, Redis state,
  proxy tree, keys, applications, services, or server records. The verified
  conversion is in place.
- Do not run a broad Compose lifecycle command. The only container this
  recovery may remove, recreate, and start is `coolify-proxy`, because it was
  created but never started. Preserve the running `coolify`, database, Redis,
  Sentinel, and auxiliary containers.
- Do not copy production `acme.json`, manually author a dynamic proxy route,
  or introduce another ingress implementation. Coolify's existing
  `coolify-proxy` remains the sole 80/443 ingress owner.
- Do not treat the recovered endpoint as fully isolated until the limitation
  below has been resolved by an operator-owned lifecycle action.

## Current automation and lifecycle limitation

The following database controls are disabled on sf0: Sentinel enablement
booleans, scheduled database backups, scheduled tasks, and email/Resend
delivery. Team notification transports are disabled as well. Those settings
prevent their automatic recreation or dispatch after a future lifecycle
operation; they do not change environment variables or processes already
inside running containers.

In particular, `coolify-sentinel` is still running with a production
`PUSH_ENDPOINT`. The existing app container's Horizon, scheduler, and SSH
master processes likewise remain tied to that already-running container.
Correcting either condition requires stopping or recreating an existing
container. The agent process rule forbids that action; it is reserved for an
operator-owned lifecycle change. Even after ingress recovery, sf0 must not be
described as fully isolated or as a clean/fresh staging installation.

## In-place recovery procedure

Record every command, container ID, file hash, and result in the recovery
ticket. Stop at any unexpected state rather than broadening the operation.

### 1. Preserve rollback evidence

Before changing state, make and retain all of the following outside the live
proxy directory:

1. A timestamped, encrypted database dump, with its checksum.
2. The exact current bytes and checksum of
   `/data/coolify/proxy/docker-compose.yml`.
3. The current proxy dynamic configuration, if present, plus `acme.json`
   metadata and the `coolify-proxy` inspect output.
4. The running `coolify` container ID and its published port mapping, proving
   that the recovery must preserve its direct port-8000 ownership.

Name the retained evidence in the ticket as `SF0_RECOVERY_DUMP` and
`SF0_RECOVERY_PROXY_COMPOSE`. They are the rollback references for this
operation. A database dump is forensic/recovery evidence; never restore it
blindly over a running control plane.

### 2. Fence the copied enrollment state

Confirm the disabled automation controls above and record their values. Then,
using Coolify's control-plane proxy state owner, clear only the stale,
production-derived enrollment for sf0's local server (`server_id = 0`). The
clear must be bounded to the copied enrollment state and occur only after the
backup in step 1. It must not delete resource inventory, users, teams, keys,
or instance settings.

The fence is complete only when the server no longer has a non-rolled-back
control-plane enrollment that blocks normal dynamic proxy generation. Preserve
the removed state in `SF0_RECOVERY_DUMP` rather than reconstructing it by hand.

### 3. Restore the ordinary proxy Compose shape

Generate the ordinary proxy configuration with
`generateDefaultProxyConfiguration($server, save: false)` and persist it
through `SaveProxyConfiguration`; do not reuse the enrollment-modified stored
configuration or hand-edit the file. The ordinary proxy configuration publishes:

```text
80:80
443:443
443:443/udp
127.0.0.1:8080:8080
```

It must not publish host port 8000 and must not include
`--entrypoints.coolify.address=:8000`. The direct Coolify app keeps its
existing host-port-8000 binding. Retain the configured proxy image, volumes,
networks, and other ordinary Compose fields unchanged.

### 4. Generate the staging dynamic route through Coolify

Set the instance FQDN to `https://dev.coolify.haiku.host` and give the
instance an unambiguous name such as `Coolify Release Staging (sf0)` through
the instance Settings owner. After the stale enrollment fence is clear,
`Server::setupDynamicProxyConfiguration()` is the canonical owner that
generates the managed `coolify.yaml` route for the dashboard, Reverb, and
terminal endpoints.

Verify the generated dynamic configuration contains
`dev.coolify.haiku.host` and no `coolify.haiku.host`,
`coolify.iocloudhost.net`, or other production control-plane hostname. Do not
substitute a manual dynamic file for the owner-generated configuration.

### 5. Start only the missing proxy

Use the corrected existing proxy Compose project to remove/recreate, if
needed, and start only `coolify-proxy`. Do not run a project-wide `up`,
restart Docker, or stop/recreate any existing container. The expected result
is that `coolify-proxy` binds 80/443 while `coolify` retains the same container
identity and direct 8000 binding recorded in step 1.

## Recovery acceptance checklist

The in-place ingress recovery is accepted only when every item is evidenced:

1. `22.haiku.host` and `https://coolify.haiku.host` are unchanged and remain
   the production control plane.
2. Public DNS for `dev.coolify.haiku.host` resolves to `75.8.210.180` and
   HTTP redirects to HTTPS.
3. TLS for `dev.coolify.haiku.host` is valid, and
   `https://dev.coolify.haiku.host/login` reaches the sf0 login page.
4. `coolify-proxy` is running and is the only sf0 listener on 80/443.
5. The existing `coolify` container ID is unchanged and it still owns host
   port 8000; no proxy listener or entrypoint claims that port.
6. The generated dynamic configuration routes only
   `dev.coolify.haiku.host`; it contains no production control-plane hostname.
7. The stale local `control_plane_proxy_enrollment` is absent or explicitly
   rolled back, so normal dynamic proxy generation is no longer suppressed.
8. Database evidence shows Sentinel enablement, scheduled backups, scheduled
   tasks, and email/Resend delivery disabled; team notification transports are
   disabled.
9. The recovery ticket contains valid `SF0_RECOVERY_DUMP` and
   `SF0_RECOVERY_PROXY_COMPOSE` references, checksums, and the before/after
   proxy inspection evidence.

Passing this checklist establishes recovered staging ingress and disabled
automations. It does not establish clean state, full isolation, or permission
to enable lifecycle-bound workers. Those claims remain blocked by the running
Sentinel and existing app process topology described above.

## Rollback

If the recovery fails before `coolify-proxy` starts, restore the exact proxy
Compose bytes identified by `SF0_RECOVERY_PROXY_COMPOSE`, then stop and review
the saved inspect output and logs. Do not start a second proxy or modify the
running app's port-8000 binding.

If a database-state rollback is considered, use `SF0_RECOVERY_DUMP` only in a
new, explicitly authorized recovery plan. Restoring a production-derived dump
can reintroduce copied enrollment and automation state, so it is not an
automatic rollback step. A staging failure never authorizes a production DNS
change, a production failover, or a lifecycle change on `22.haiku.host`.
