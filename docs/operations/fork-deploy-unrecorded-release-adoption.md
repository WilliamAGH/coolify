# Adopting an unrecorded but signed running release

Recovery runbook for the drift class where a control plane runs a release that
is legitimately signed but was never recorded by `scripts/fork-deploy`, so every
sanctioned path (`update`, `repair`, `rollback`, and the plain
`reconcile-migrated-state`) refuses.

## When this applies

The host reaches this state when releases were applied by hand — typically by
swapping the pinned digest in `/data/coolify/source/docker-compose.custom.yml`
and running `docker compose up` — instead of through `fork-deploy update`. The
running code and the database schema then advance past the last recorded
activation, and no recorded release is reachable from reality any more.

The signature of the state is all four of these refusing together:

```
fork-deploy update --offline-manifest ... --dry-run
  ERROR: docker-compose.custom.yml differs from the verified release; run repair

fork-deploy repair --dry-run
  ERROR: repair refused: current migration fingerprint differs from the verified
  release; use the control-plane migration recovery path

fork-deploy reconcile-migrated-state
  ERROR: <running image> does not match the recorded signed release digest

fork-deploy rollback --version <RECORDED> --dry-run
  ERROR: rollback refused: current migration fingerprint <X> is not explicitly
  compatible with target <RECORDED> (<Y>). Use the control-plane migration
  recovery path for a schema-changing rollback
```

Confirm the recorded and running identities before doing anything:

```bash
cat /data/coolify/fork-deploy/current
grep -o 'coolify@sha256:[a-f0-9]\{64\}' /data/coolify/source/docker-compose.custom.yml
docker inspect --format '{{.Config.Image}}' coolify
```

If the recorded version equals the running version, this runbook does **not**
apply; use plain `fork-deploy reconcile-migrated-state` (bridge-migration drift)
or `fork-deploy repair`.

## What adoption does and does not relax

`fork-deploy reconcile-migrated-state --offline-manifest ABSOLUTE_PATH` adopts
the running release into the managed state. It does not weaken the trust anchor:

- Plain `reconcile-migrated-state` proves *the running code is the **recorded**
  signed release*.
- Adoption proves *the running code is **a** signed release whose manifest
  passes the same detached Ed25519 verification `install` and `update` require* —
  same `fd_load_manifest` code path, same trusted key
  (`/etc/coolify-fork/release-signing-ed25519.pub` in production), same `KEY_ID`
  equality check.

The operator supplies that manifest; the tool never infers a release from
whatever happens to be running. Every equality below is evaluated against the
**supplied signed manifest**, not against the host:

| Check | Refuses on |
|---|---|
| Detached Ed25519 signature + `KEY_ID` | unsigned, badly signed, or untrusted-key manifest |
| Manifest schema and field order | malformed or reordered manifest |
| `release.manifest` identity for the version | a version already bound to a different signed manifest |
| `docker-compose.yml`, `docker-compose.prod.yml`, `docker-compose.custom.yml`, `.env.production` sha256 | signed-asset drift |
| `AUTOUPDATE`, `VERSIONS_URL`, `UPGRADE_SCRIPT_URL`, `RELEASES_URL`, port distinctness | fail-closed environment drift |
| Effective Compose images and published bindings | mutable image references, missing pins, unsafe or duplicated bindings |
| Running `coolify`, `coolify-db`, `coolify-redis` image digests | running code that is not the supplied signed release |
| Managed state integrity | missing/unreadable current pointer, unresolved pending or forward-recovery state, corrupt recorded bundle |

Only the migration fingerprint and the rendered-Compose sha are treated as
adoptable drift, exactly as in the bridge-migration reconcile.

Constraints:

- Only `--offline-manifest` is accepted. The manifest and its `.sig` must be
  placed on the host as absolute paths; the recovery path takes no network
  input, no `--manifest` URL, no `--version`, and no `--dry-run`.
- Adoption targets a `coolify-fork-release/v2` manifest. A v1 (separate
  `coolify-realtime` container) manifest is still accepted only as the active
  predecessor of a v2 update.
- The message `... differs from the verified release; run repair` emitted by the
  asset check during adoption means the on-disk asset differs from the
  **supplied** manifest. Do **not** run `repair` in response — that re-activates
  the old recorded release. Fix the asset drift or stop and investigate.

## Procedure

1. **Identify the release that is actually running.** Match the running
   `coolify` image digest to a published fork release. The signed manifest and
   detached signature for that release are the ones published with it.

2. **Copy the signed manifest pair onto the host**, root-owned, outside the
   managed state directory, for example:

   ```bash
   install -o root -g root -m 0600 release.manifest     /root/adopt/release.manifest
   install -o root -g root -m 0600 release.manifest.sig /root/adopt/release.manifest.sig
   ```

   Both files must be regular, non-symlinked, non-hardlinked files, and the
   signature must be at `<manifest path>.sig`.

3. **Confirm the recorded state has no unresolved deployment.** `fork-deploy
   status` must not report a pending or forward-recovery state. If it does,
   finish `recover-abort` or `recover-forward` first — adoption refuses while
   either is outstanding.

4. **Adopt:**

   ```bash
   fork-deploy reconcile-migrated-state --offline-manifest /root/adopt/release.manifest
   ```

   On success the tool records the immutable signed bundle for the adopted
   version under `/data/coolify/fork-deploy/releases/<VERSION>/`, writes the
   activation (with `MIGRATION_FINGERPRINT_BEFORE` preserved from the last
   recorded activation and `MIGRATION_FINGERPRINT_AFTER` plus
   `RENDERED_COMPOSE_SHA256` taken from post-drift reality), moves the current
   pointer, appends an `adopt-running-release` row to `history.tsv`, and re-runs
   the full verifier before exiting.

5. **Verify:**

   ```bash
   fork-deploy verify
   fork-deploy status
   ```

   `update`, `repair`, and `rollback` are available again from the adopted
   version. Releases recorded before the drift remain rollback targets only
   where the migration fingerprints are explicitly compatible; a
   schema-advancing gap still refuses by design.

## If adoption refuses

Adoption is fail-closed and non-mutating up to the point where the bundle is
recorded. Read the refusal literally:

- `release manifest signature verification failed` — the manifest/signature pair
  is not the published one, or the host's trusted key is not the release signing
  key. Re-fetch the published pair; never work around this.
- `does not match the signed release image digests for <VERSION>` — the supplied
  manifest is not the release that is running. Either the wrong manifest was
  supplied, or the overlay was swapped without recreating the container. Do not
  adopt; establish which release is actually running first.
- `<asset> differs from the verified release` — the on-disk asset is not the one
  the supplied manifest signs. A hand-swap that edited only some assets lands
  here. Restore the exact published asset for that release, or stop.
- `is already bound to a different signed release manifest` — that version was
  previously recorded from a different manifest. Stop and investigate; this is a
  release-identity conflict, not drift.

Nothing in this runbook authorizes editing the trusted key, bypassing
verification, or hand-writing state under `/data/coolify/fork-deploy/`. If the
refusal cannot be resolved with a published, correctly signed manifest, report
the blocker per `shared-production-change-control.md`.
