# Corpus shard observability contract

This contract records a requirement for a future corpus epoch. It is not
implemented by the active p80 epoch and must not be retrofitted into an
identity-bound run.

## Purpose

The terminal shard report is intentionally emitted as one JSON document only
after every tournament has been processed. A zero-byte temporary report is
therefore normal while a shard runs, but it cannot distinguish healthy CPU
work from silent non-progress. A future runner must publish a separate,
non-evidentiary heartbeat without weakening terminal report promotion.

## Required heartbeat

The machine-readable envelope is frozen as
`docs/schema/corpus-shard-heartbeat-1.schema.json`. The independent reader
`tools/verify_corpus_shard_heartbeat.php` enforces the schema boundary,
campaign identity and monotonic continuity between two snapshots. Neither the
schema nor the reader is a writer, and adding them does not alter the active
runner.

Each worker must atomically replace one private heartbeat JSON file after each
completed tournament and at least once every five minutes while work is
continuing. The file must contain:

- the campaign, shard and immutable runtime/source/harness/lock hashes;
- a process-start token that changes after every worker restart;
- UTC start and observation timestamps plus monotonic elapsed seconds;
- total and completed file counts;
- tournaments verified and rounds attempted so far;
- exact, colour-variant, deviation, no-solution and resource-limit counters;
- SHA-256 of the last completed input path relative to the corpus root, never
  the path or corpus content itself;
- the configured arithmetic backend and whether GMP and CLI JIT were observed;
- `terminal: false` and `acceptedEvidence: false`.

Publication must use a same-directory temporary file, `0600` permissions and
one atomic rename. Readers must reject a symlink, a non-regular file, a stale
timestamp, an identity mismatch, decreasing counters, completed counts above
declared totals, or a process-start token that does not match the observed
worker.

## Separation from evidence

The heartbeat is operational telemetry only. It cannot:

- count as an accepted shard or classification;
- replace the terminal verifier JSON;
- survive into an aggregate denominator unless the terminal report and
  fail-closed classification are both promoted;
- contain player names, FIDE IDs, TRF text or absolute paths; or
- justify changing an ETA until at least one terminal shard is accepted.

When the terminal report is ready, the runner must stop heartbeat updates,
promote report and classification through the existing gate, and only then
remove or archive the heartbeat as non-evidence.

## Crash and resume behavior

After a crash, a new worker must use a new process-start token and restart the
non-checkpointable shard from zero unless a separately specified deterministic
checkpoint format has been accepted. A stale heartbeat from the previous
token is an infrastructure warning, not progress for the new process.

Before enabling this channel, its write overhead must be benchmarked against
the frozen p80 fixture and remain below one percent. Its schema, writer,
reader, negative tests and runner hashes then become part of the next campaign
identity. The active `p80-epoch4-86ec9d9` remains unchanged.
