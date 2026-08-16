# ChessPairings integration inventory

This began as a read-only inventory of the local ChessPairings web checkout on
16 August 2026. The active web-lab release itself did not expose a usable Git
identity, so a sanitized copy was frozen separately as non-deployed candidate
`5.21.0-rc.19`; the isolated attempt-ledger refinement is frozen as rc.20. No
production request, database write or deployment was made.

The exact inspected file hashes and findings are recorded in
`evidence/chesspairings-pairing-path-inventory-20260816.json`. The isolated
freeze, TRF correction and executable adapter checkpoint are recorded in
`evidence/chesspairings-shadow-adapter-bootstrap-20260816.json`.
The rc.20 pre-execution attempt ledger, portable test and source freeze are
recorded in
`evidence/chesspairings-shadow-attempt-ledger-checkpoint-20260816.json`.

The current isolated candidate is commit
`b247db5a37bc497acac5b128a752ba70a1c7ba23`, derived from rc.19 commit
`d63de7759c59c13cb9168a5befac51f70e84149c`, itself derived from the rc.18 archive
whose SHA-256 is
`75f6674f2e48a9d851cfea9672fe72550d28f04fbca08abda08a91c412639953`.
The source-freeze verifier reports 604 tracked files, source-manifest SHA-256
`11affb80a854536aeee4477f6669bd123e76cf18b8af466365561cce57cc7271`,
92 migration files and zero secret-scan findings. Two credentials embedded in
the parent distribution were removed from the candidate; owner-side rotation
of the old value remains required and is not claimed by this repository.

## Current seam

`classes/Abbinamenti.php` owns the individual pairing flow. It constructs a
TRF snapshot, selects one of four HTTP pairing services, submits synchronous
or asynchronous work, parses a compact text response and writes games and
history. OpenPairingPHP is not one of the selectable engines in the active
snapshot. The isolated rc.19 candidate now contains a direct, read-only
`OpenPairingShadowAdapter` and a pre-execution immutable attempt sink, but no
application call site enables either one.
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

1. **No final combined integration identity.** The rc.20 source and migrations
   are now frozen, and the adapter verifies an exact OpenPairing lock plus THP
   candidate manifest. The final installer/runtime/PTC/RTG artifact hashes do
   not exist yet, so this is not the submitted identity.
2. **No fail-closed FIDE-mode route.** Unknown engine values fall back to a
   legacy engine port and manual pairing remains a product option. A future
   FIDE mode needs an explicit allow-list and must not silently select another
   engine or permit a fallback into accepted evidence.
3. **No live immutable input/revision check.** The candidate can build the
   canonical request and reject a stale supplied revision or hash, but the
   asynchronous product path still stores no attested database revision and
   does not perform the required immediate live reread.
4. **No atomic commit boundary.** Pairing, game/history writes, point updates,
   round advancement and bye resets are separate operations. Some paths delete
   existing rows before calculation or validation. The submitted path needs a
   transaction with a final revision/hash comparison and complete rollback.
5. **Output accounting is permissive.** The parser can skip absent or short
   pair lines and does not itself prove that every eligible player appears
   exactly once, IDs are unique and reciprocal, the count matches the declared
   output or all hard constraints remain valid. Draft warnings are explicitly
   non-blocking.
6. **First-round random colour is not frozen.** Before a saved first round, a
   retry can draw a new random initial colour while generating the TRF. The
   selected value must be persisted before the engine call and included in the
   canonical input identity.
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

1. **Done:** freeze a source-controlled ChessPairings candidate and its 92-file
   database migration set.
2. **Partial:** the product TRF26 codec maps directly into OpenPairing with an
   executable golden fixture; an immutable request and portable precommit guard
   exist, while live revision acquisition and persistence remain.
3. **Partial:** the local adapter, comparator, event envelope, atomic event
   sink and pre-execution attempt ledger exist and fail closed on identity
   drift, duplicate reservations or unclassified differences, but no shadow
   call site is enabled.
4. Prove stale-revision and injected-failure rollback tests before any live
   observation.
5. Bind every shadow event to the exact identity manifest and validate batches
   with `tools/verify_shadow_evidence.php`.
6. Only after the shadow and complete THP workflow gates close, create a new
   separately authorised release candidate. No step here authorises a
   production change.

The executable acceptance sequence and required synthetic fixture families
are enumerated in `THP-PRODUCT-ACCEPTANCE-PLAN.md`.
