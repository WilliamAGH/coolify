# Warm standby refresh and takeover

Repeatable, repository-versioned procedure for refreshing the warm standby
(`popos-sf0`) from the running production control plane (`22.haiku.host`)
without a production stop window, and for taking the standby over as the
production control plane if that ever becomes necessary.

This replaces the hand-preserved schema-v1 copies at
`/root/control-plane-migrate.sh.v1-live-capture`. Once a refresh has been
completed with the repository-versioned tool, delete those copies on **both**
hosts (see "Retiring the v1 copies" below).

Tooling: `scripts/control-plane-migrate.sh` (manifest schema
`coolify-control-plane-migration/2`) with the `--live-standby-seed` capture
mode. The script is standalone: copy the single file to the host and run it
with bash; it needs only docker, coreutils, tar, sha256sum, flock, and python3
(for atomic no-replace publication). It is architecture-neutral — the arm64
source (`22.haiku.host`) and amd64 standby (`popos-sf0`) interoperate because
the database travels as a PostgreSQL custom-format dump and trees travel as
tar archives; no host binaries are copied.

## Guarantee model

A `--live-standby-seed` capture keeps the source running. Exactly one of
`--require-source-stopped` or `--live-standby-seed` must be passed; the modes
are mutually exclusive and freeform `--attest-quiesced` attestations remain
rejected.

What a live standby seed **may** claim (recorded in `manifest.env` and
`manifest.json` as `CAPTURE_MODE=live-standby-seed`,
`CONTROL_PLANE_STATE_CONTRACT=live-standby-seed-unverified`, plus the
guarantee text):

- The database dump is transactionally consistent (single `pg_dump` snapshot).
- Tree archives are crash-consistent and were taken while both canonical
  control-plane writer locks were held (no concurrent enrollment writer could
  mutate the proxy tree during the copy).
- `APP_KEY` is present and checksums cover every artifact.
- The capture is read-only with respect to the running source: no containers
  are stopped or started, no compose changes are made, and `--capture-redis`
  is refused in this mode because `BGSAVE` would mutate source state. The only
  source-filesystem writes are the two canonical writer-lock files (only if
  absent) and the designated `--output` directory.

What it **may not** claim:

- The absent control-plane state contract. The source keeps running, so the
  archive may carry durable control-plane state (enrollment / generation rows,
  the listener override, managed Traefik writer state) and no fingerprint
  stability is asserted.
- Coherence between the trees and the database, or queue/job coherence. Redis
  is never captured in this mode.

Consequences enforced by the tool:

- Restore of a live-seed archive refuses `--enable-workers` outright; a
  production takeover requires this runbook, never a flag.
- `--restore-proxy` is refused for every schema-v2 archive.
- Restore tolerates durable control-plane **database** state on the standby
  target (its database is dropped and re-seeded; a second refresh must not be
  blocked by rows the first refresh imported) while the standby's own
  **filesystem** must stay free of control-plane listener/writer state.

## Preconditions

- The standby is fork-deploy managed and `fork-deploy verify` is green.
- The standby runs the **same fork release and image digest** as production
  (restore proves `--expect-fork-version` and `--expect-image-digest`).
- The standby's filesystem carries no control-plane listener override
  (`source/docker-compose.control-plane-listener.yml`) or managed Traefik
  writer state beyond the two canonical lock files.
- Workers are disabled on the standby (`HORIZON_ENABLED=false`,
  `SCHEDULER_ENABLED=false`) and no router or ACME entry exists for the
  production hostname.
- `/data/coolify/proxy` on the standby is root-owned (restore fails closed
  otherwise). The v1 seeding left it owned by uid 9999; match production
  before the first restore:

  ```bash
  chown root:9999 /data/coolify/proxy && chmod 710 /data/coolify/proxy
  ```

## Refresh procedure

Run every step as root. Placeholders: `<STAMP>` is a UTC timestamp you choose
once and reuse; `<X.Y.Z-fork>` and `sha256:<digest>` come from the standby
itself in step 4.

1. **Ship the tool to both hosts** (from a repository checkout):

   ```bash
   scp scripts/control-plane-migrate.sh root@22.haiku.host:/root/control-plane-migrate.sh
   scp scripts/control-plane-migrate.sh root@popos-sf0:/root/control-plane-migrate.sh
   ```

2. **Capture on the source (production keeps running)** — on `22.haiku.host`:

   ```bash
   STAMP=$(date -u +%Y%m%d%H%M%S)
   bash /root/control-plane-migrate.sh capture \
     --output /data/coolify/control-plane-migrate-standby-seed-$STAMP \
     --live-standby-seed
   echo $STAMP
   ```

   If the census reports an undecided top-level directory under
   `/data/coolify`, decide it explicitly with `--include NAME` or
   `--exclude NAME` and re-run; the tool never guesses. Do not pass
   `--capture-redis` (refused in this mode) and do not stop anything.

   Decisions made on the first refresh (2026-07-30), as the reference set:
   `--exclude control-plane-attestor` (control-plane proxy attestor
   workspaces; must never travel to a standby), `--exclude sentinel`
   (actively written host-local metrics sqlite; meaningless off-host),
   `--include log-drains --include ssl --include webhooks-during-maintenance`
   (ordinary instance data). Re-evaluate any new undecided directory on its
   own merits.

3. **Transfer to the standby** — on `popos-sf0`:

   ```bash
   rsync -a --info=progress2 \
     root@22.haiku.host:/data/coolify/control-plane-migrate-standby-seed-<STAMP>/ \
     /data/coolify/control-plane-migrate-standby-seed-<STAMP>/
   ```

   If host-to-host SSH is not authorized (neither host holds a key for the
   other — the state observed on the first refresh), relay the archive
   through the operator machine with a tar pipe instead; step 4's `verify`
   re-checks every artifact checksum on the target, so the relay adds no
   integrity risk:

   ```bash
   ssh root@22.haiku.host \
     'tar -C /data/coolify -cf - control-plane-migrate-standby-seed-<STAMP>' \
     | ssh root@popos-sf0 'tar -C /data/coolify -xf -'
   ```

4. **Verify the archive and read the standby's release identity** — on
   `popos-sf0`:

   ```bash
   bash /root/control-plane-migrate.sh verify \
     --archive /data/coolify/control-plane-migrate-standby-seed-<STAMP>
   grep '^COOLIFY_FORK_VERSION=' /data/coolify/source/.env
   grep -o 'coolify@sha256:[a-f0-9]\{64\}' /data/coolify/source/docker-compose.custom.yml
   ```

   Verify prints the reduced-guarantee line for live archives; that is
   expected.

5. **Restore without workers and without proxy** — on `popos-sf0`:

   ```bash
   bash /root/control-plane-migrate.sh restore \
     --archive /data/coolify/control-plane-migrate-standby-seed-<STAMP> \
     --authorize-overwrite "$(hostname)" \
     --expect-fork-version <X.Y.Z-fork> \
     --expect-image-digest sha256:<digest>
   ```

   Never pass `--enable-workers` (refused for live-seed archives) and never
   pass `--restore-proxy` (refused by schema v2). The restore stops the
   standby's app container, backs the standby up, re-seeds the database, and
   restarts the app with workers disabled.

   If restore fails closed with `effective APP_KEY mismatch`, the app
   container must be recreated from its compose project. **Before any
   `docker compose ... up` on the standby, verify the pinned coolify digest
   matches the intended release** (pin-revert hazard — an unrelated writer has
   previously rewritten the pin):

   ```bash
   grep -o 'coolify@sha256:[a-f0-9]\{64\}' /data/coolify/source/docker-compose.custom.yml
   ```

   Only proceed with the compose recreate if that digest equals the
   `sha256:<digest>` you passed to restore, then re-run restore with a fresh
   `--target-backup-dir`.

6. **Reconcile release tracking and verify** — on `popos-sf0`:

   Restore invokes `fork-deploy reconcile-migrated-state` automatically when
   the tool is resolvable; if it logged that the tooling was not found, run it
   yourself, then verify:

   ```bash
   fork-deploy reconcile-migrated-state
   fork-deploy verify
   ```

   This form re-records only the migration fingerprint and rendered-Compose sha
   and requires the running image digests to equal the **recorded** signed
   release. If it refuses because the host is running a signed release that was
   never recorded (releases applied by hand-swapping the Compose overlay), use
   `fork-deploy-unrecorded-release-adoption.md` instead — it adopts the running
   release only against an operator-supplied, signature-verified manifest.

7. **Post-refresh checks** — on `popos-sf0`:

   ```bash
   grep -E '^(HORIZON_ENABLED|SCHEDULER_ENABLED)=' /data/coolify/source/.env   # both false
   ```

   Compare the post-restore inventory printed by restore against the manifest
   inventory. Confirm no router and no ACME entry exists for the production
   hostname (see the hazard below).

## Cadence

The refresh is **manual and scheduled by convention, not automation**: run it
**weekly**, and additionally **before any risky control-plane change** on
production (release rollouts that touch the control plane, proxy/enrollment
changes, migrations). A standby seed older than two weeks should be treated as
stale for takeover purposes. Automating the refresh is deliberately out of
scope: capture holds production's control-plane writer locks, and an
unattended failure mode that leaves preserved staging directories or a
half-restored standby needs an operator anyway.

## The instance_fqdn / ACME hazard

The restored database carries `instance_fqdn = https://coolify.iocloudhost.net`
(the production hostname). Nothing on the standby may generate a router for
that hostname: workers stay disabled, no proxy configuration is restored, and
`acme.json` stays empty. If the standby's dynamic proxy configuration were
ever regenerated while it holds the production `instance_fqdn`, it would
request ACME certificates for the production hostname and consume Let's
Encrypt failure limits shared with production renewals.

Concretely: never enable Horizon/the scheduler, never run proxy
(re)generation, and never start a Traefik that would serve the production
hostname on the standby during refresh. Stopping the standby's proxy container
is **not** a valid hardening step either — the proxy is part of the verified
Compose state, so stopping it turns `fork-deploy verify` red and its `repair`
guidance re-activates the recorded release.

## Takeover (promoting the standby to production)

Takeover is a deliberate, operator-driven procedure. There must be **exactly
one** mutable production control plane at any moment; enabling workers on the
standby while the production plane can still run them is the one unrecoverable
mistake this runbook exists to prevent.

1. **Fence the old plane.** Confirm the production control plane on
   `22.haiku.host` is stopped or unreachable and cannot restart its workers
   (stop the app container and disable its compose stack, or confirm the host
   is down). Record the decision and the data-loss window: the standby serves
   the last seed, so everything after `CREATED_AT_UTC` in the seed's manifest
   is lost.
2. **Verify release identity on the standby** (`fork-deploy verify`, and check
   the pinned digest in `/data/coolify/source/docker-compose.custom.yml`
   matches the release you intend to run — pin-revert hazard, as above).
3. **Reconcile imported control-plane state.** A live seed may have imported
   durable enrollment / generation rows describing the dead plane's proxy
   state. Roll back or reconcile that state (the enrollment/promotion
   reconciliation procedures) before any blue/green operation runs on the new
   plane.
4. **Enable workers on exactly one plane.** On the standby set
   `HORIZON_ENABLED=true` and `SCHEDULER_ENABLED=true` in
   `/data/coolify/source/.env`, then recreate the app container from its
   compose project (after the pin-digest check) so the environment takes
   effect.
5. **Move the hostname.** Point DNS for the `instance_fqdn`
   (`coolify.iocloudhost.net`) at the standby. Only after DNS resolves to the
   standby, regenerate the proxy configuration so Traefik obtains ACME
   certificates for the hostname on the new plane. Dogfood with forced DNS
   resolution before the public DNS change.
6. **Re-verify.** `fork-deploy verify` green, public health checks green,
   deployments and realtime working. The old host, if it ever returns, must
   come back with workers disabled and must be treated as a standby candidate,
   never as a second control plane.

## Retiring the v1 copies

The v1 live-capture script survives only as unversioned copies at
`/root/control-plane-migrate.sh.v1-live-capture` on `22.haiku.host` and
`popos-sf0`. It receives no review or tests and drifts from the supported
tool. After the first successful refresh with `--live-standby-seed`:

```bash
ssh root@22.haiku.host rm -f /root/control-plane-migrate.sh.v1-live-capture
ssh root@popos-sf0 rm -f /root/control-plane-migrate.sh.v1-live-capture
```
