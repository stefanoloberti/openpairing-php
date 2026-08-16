# ChessPairings integration inventory

This began as a read-only inventory of the local ChessPairings web checkout on
16 August 2026. The active web-lab release itself did not expose a usable Git
identity, so a sanitized copy was frozen separately as non-deployed candidate
`5.21.0-rc.19`; the isolated attempt ledger was frozen as rc.20, the
synthetic observation coordinator as rc.21, the Team staging line progressed
independently through rc.30, and the initial-colour correction is frozen as
source-only rc.31. The atomic individual result-batch correction is frozen as
source-only rc.32, and English catalogue parity is frozen as source-only
rc.33. No production request was made; none of these corrections changed a
database or staging.

The exact inspected file hashes and findings are recorded in
`evidence/chesspairings-pairing-path-inventory-20260816.json`. The isolated
freeze, TRF correction and executable adapter checkpoint are recorded in
`evidence/chesspairings-shadow-adapter-bootstrap-20260816.json`.
The rc.20 pre-execution attempt ledger, portable test and source freeze are
recorded in
`evidence/chesspairings-shadow-attempt-ledger-checkpoint-20260816.json`.
The rc.21 coordinator and its still-open live transaction boundary are
recorded in
`evidence/chesspairings-shadow-observation-coordinator-checkpoint-20260816.json`.
The broader rating-import, operator, FIDE-mode, recovery, TRF, English UI and
package audit is frozen in `CHESSPAIRINGS-PRODUCT-WORKFLOW-AUDIT.md` and
`evidence/chesspairings-product-workflow-audit-20260816.json`. That audit is a
complete inventory, not a closed product gate.
The rc.31 record-`152`, one-time-colour and atomic-start source checkpoint is
recorded in
`evidence/chesspairings-initial-colour-checkpoint-20260816.json`.
The rc.32 all-or-nothing, round-bound and per-board revision checkpoint is
recorded in
`evidence/chesspairings-atomic-result-batch-checkpoint-20260816.json`.
The rc.33 zero-missing-key English catalogue checkpoint is recorded in
`evidence/chesspairings-english-catalogue-checkpoint-20260816.json`.

The current isolated candidate is commit
`7baabc4abdd714d719d4de1a5417407fc6bcd2cc`, derived from the exact clean
rc.32 commit `7a6c4d315410d2c8f0b63199ae460448ce93e250`. The rc.33 bundle SHA-256 is
`2a927babf61cc086f1920a57880765166227bc882875afda1431bab20fc1b25d`;
the source archive is reproduced four ways at SHA-256
`31c7075ce82bc51c747e4aa7a5736b9ff81cbc1fc8d5216eb9b8a5e16c1eb31a`.
The archive has 669 entries, no environment config or symlink, and the
distribution scan examined 499 text files with zero findings. Owner-side
rotation of the credential removed earlier remains required and is not
claimed by this repository.

## Current seam

`classes/Abbinamenti.php` owns the individual pairing flow. It constructs a
TRF snapshot, selects one of four HTTP pairing services, submits synchronous
or asynchronous work, parses a compact text response and writes games and
history. OpenPairingPHP is not one of the selectable engines in the active
snapshot. The isolated rc.19 candidate now contains a direct, read-only
`OpenPairingShadowAdapter`, a pre-execution immutable attempt sink and a
synthetic coordinator with a fresh-snapshot callback, but no application call
site or real transaction implements that callback.
The existing code already models requested byes, withdrawals, initial colour,
record `142`, draft pairings and product ownership checks; those are useful
inputs to a future adapter, not proof that the adapter exists.

The desktop synchronisation schema has a revision counter, but the inspected
web pairing path does not use that revision as a compare-and-swap boundary.
The future adapter should reuse the concept, not infer that the current web
and Desktop identities are interchangeable.

## Submission-blocking gaps

These are product integration gaps, not defects attributed to the OpenPairing
engine:

1. **No final combined integration identity.** The rc.21 source and migrations
   are now frozen, and the adapter verifies an exact OpenPairing lock plus THP
   candidate manifest. The final installer/runtime/PTC/RTG artifact hashes do
   not exist yet, so this is not the submitted identity.
2. **No fail-closed FIDE-mode route.** Unknown engine values fall back to a
   legacy engine port and manual pairing remains a product option. A future
   FIDE mode needs an explicit allow-list and must not silently select another
   engine or permit a fallback into accepted evidence.
3. **No live immutable input/revision check.** The candidate can build the
   canonical request and reject a stale supplied revision or hash. The
   coordinator proves the required ordering around an explicit callback, but
   the asynchronous product path still stores no attested database revision
   and does not implement the required immediate live reread.
4. **No atomic commit boundary.** Pairing, game/history writes, point updates,
   round advancement and bye resets are separate operations. Some paths delete
   existing rows before calculation or validation. The submitted path needs a
   transaction with a final revision/hash comparison and complete rollback.
5. **Output accounting is permissive.** The parser can skip absent or short
   pair lines and does not itself prove that every eligible player appears
   exactly once, IDs are unique and reciprocal, the count matches the declared
   output or all hard constraints remain valid. Draft warnings are explicitly
   non-blocking.
6. **First-round source correction is not yet runtime-proved.** Rc.31 now
   persists one row-locked draw and record-`152` provenance before TPN
   assignment inside the atomic start boundary. Migration, concurrent-start
   and rollback behavior still need exact-candidate isolated-staging probes.
7. **No live FIDE-path request/result attestation.** The isolated adapter now
   returns canonical input/output hashes and exact runtime identity without
   personal fields. The candidate can build a fail-closed versioned event and
   publish it as one immutable atomic file and reserve every selected attempt
   before execution, but no live call site yet invokes those primitives or
   records the incumbent hash and revision.
8. **Post-engine transformations need ownership.** Requested byes and withdrawn
   players are removed from or appended to the engine output. The final
   candidate must either model these in the canonical engine input/output or
   attest and validate the deterministic transformation as part of the adapter.

## Required adapter boundary

The first isolated implementation should be a new adapter beside the current
path, never an in-place production switch. It must:

- build the canonical snapshot specified by `THP-INTEGRATION-CONTRACT.md`;
- persist an opaque job ID, tournament revision, input SHA-256 and the exact
  candidate identity without player names in evidence;
- call only the frozen OpenPairing public API and require the expected engine
  identity in the result;
- run independent product-side completeness and hard-constraint validation;
- re-read and compare the revision and input hash immediately before an atomic
  transaction;
- keep shadow output read-only and outside publication, notification and retry
  behavior;
- expose explicit failure states for stale input, identity drift, timeout,
  no-solution, resource limit, malformed output and unsupported TRF state.

## Safe implementation order

1. **Done:** freeze a source-controlled ChessPairings candidate and its 94-file
   database migration set.
2. **Partial:** the product TRF26 codec maps directly into OpenPairing with an
   executable golden fixture; an immutable request and portable precommit guard
   exist, while live revision acquisition and persistence remain.
3. **Partial:** the local adapter, comparator, event envelope, atomic event
   sink, pre-execution attempt ledger and synthetic coordinator exist and fail
   closed on identity drift, duplicate reservations, stale rereads or
   unclassified differences, but no shadow call site is enabled.
4. Prove stale-revision and injected-failure rollback tests before any live
   observation.
5. **Source complete, runtime open:** rc.31 freezes individual initial-colour
   provenance and atomic start; apply migration 111 only to isolated staging
   and retain the concurrent-start/rollback evidence.
6. **Source complete, runtime open:** rc.32 makes individual result batches
   all-or-nothing, round-bound and per-board revision-bound; retain stale-client,
   concurrent-submit and injected-failure rollback evidence on isolated staging.
7. **Source complete, visual workflow open:** rc.33 supplies every Italian
   catalogue key in English; translate the hard-coded rating-import page and
   retain exact-candidate English visual/manual evidence.
8. Bind every shadow event to the exact identity manifest and validate batches
   with `tools/verify_shadow_evidence.php`.
9. Only after the shadow and complete THP workflow gates close, create a new
   separately authorised release candidate. No step here authorises a
   production change.

The executable acceptance sequence and required synthetic fixture families
are enumerated in `THP-PRODUCT-ACCEPTANCE-PLAN.md`.
