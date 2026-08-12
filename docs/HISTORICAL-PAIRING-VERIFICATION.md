# Historical pairing verification

## Pairing baseline epoch 2 (2026-08-12)

The pairing replay baseline was reset after finding that the harness recorded a
PAB as no historical float. Under FIDE Dutch semantics a PAB, and any unplayed
scoring allocation worth more than the configured loss score, is a downfloat
for the following round. Reports now use schema
`openpairing.trf-historical-pairing-verification/3` and attest
`historicalFloatSemantics: fide-dutch-float/2-pab-downfloat`.

All pairing figures produced before this semantic epoch are invalid as gate
evidence, including 426/74, 441/59, shadow 425/427/444/466 and every associated
criterion distribution. They remain in this document only as debugging
provenance and must not be compared with epoch-2 results. Parser decoding and
TRF round-trip counts are unaffected because the defect was in reconstructed
pairing history, not parsing.

The first attested epoch-2 replay on the frozen 100x7 sample has corpus manifest
`e05981129109020ff5b35a77d972b3c36887823399dc2f5b7fd0e80d8b8bad44`:

- bounded legacy: 455/500 exact;
- persistent shadow: 489/500 exact, zero no-matchings;
- shadow buckets: 450 both exact, 39 progress, 5 regressions, 4 same
  deviations and 2 divergent deviations;
- all 11 non-exact shadow rounds are Gacrux-inferior.

The epoch-2 small-field gate is now closed at 500/500 exact rounds: 455
`both-exact`, 45 `candidate-progress`, zero regressions, zero deviations and
zero no-matchings. The attested report is
`docs/evidence/pairing-epoch2-dutch-500.json`; source tree SHA-256 is
`62c99333645817d956f37c3e41808561c1312d219f89fdba8061e2b400c53e4f`,
corpus manifest SHA-256 remains
`e05981129109020ff5b35a77d972b3c36887823399dc2f5b7fd0e80d8b8bad44`, and
the checkpoint is Git tag `epoch-2-dutch-500` at commit `f419b7c`.

The larger-field gate is still open for performance. Ten-tournament samples
at 30 and 50 players completed with zero shadow no-matchings; the bounded
legacy path hit 23 and 78 resource limits respectively. A ten-tournament
80-player run was terminated after 48 minutes at full CPU before producing its
first report. This confirms that polynomial matching removed the enumerator's
correctness guard-rail, while from-scratch Blossom recomputation at every
freeze remains too slow for the full corpus. No production switchover has been
made.

The first BBP-faithful PAB representation (the bye is the sole unmatched real
vertex, not an edge to a virtual vertex) is checkpointed at 490/500, zero
no-matchings. All ten residuals are Gacrux-inferior. Its compact, versioned
evidence is `docs/evidence/pairing-epoch2-v3-pab-unmatched-490.json`; mutable
`build/` reports are laboratory detail rather than the documentation source of
truth. The checkpoint remains shadow-only while the matched/remainder/exchange
lifecycle is incomplete.

The retained conditional-C9 bracket lifecycle raises the attested shadow
checkpoint to 494/500 with zero no-matchings (452 both exact, 42 progress, 3
regressions, 1 same deviation and 2 divergent deviations). All six residuals
are Gacrux-inferior; their first differences are three HE2 cases plus QC15,
QC17 and QC18. The authoritative counters and identities are in
`docs/evidence/pairing-epoch2-lifecycle-c9-494.json`; the complete oracle
analyses are the adjacent `gacrux-lifecycle-c9-494-*.json` reports. The gate
remains open at 500/500.

Parser validation is not pairing-engine verification. The historical verifier
reconstructs the tournament state immediately before every recorded round,
runs the native PHP Dutch engine, then compares opponents, colours and PAB with
the reference round stored in the TRF.

Run a directory with:

```console
php tools/verify_trf_pairings.php DIRECTORY [MAX_FILES]
```

Every report includes the resolved Composer PSR-4 root, loaded Dutch engine
path and SHA-256, complete `src/**/*.php` tree SHA-256 and engine version. The
verifier exits with code 4 before reading the corpus if Composer resolves
another checkout. A prepared validation workspace also contains
`runtime-identity.lock.json`; modifying or copying a source after preparation
causes the same fail-closed result. Parallel aggregation accepts exactly one
source-tree identity across all child reports.

Every report also attests the exact data portion consumed: TRFs are selected in
ordinal relative-path order, then the requested limit is applied. The corpus
manifest SHA-256 covers each relative path, byte length and raw-file SHA-256.
Reports therefore identify both “this code” and “these exact input bytes”. The
parallel launcher deterministically hashes its ordered child manifests into an
aggregate corpus identity.

Prepare one immutable remote validation checkout with:

```console
bash tools/prepare_validation_workspace.sh ARCHIVE /tmp/openpairing-validation VENDOR_DIRECTORY
```

This creates a content-addressed release and atomically updates `current`.
Dependencies are physically copied: a symlinked Composer `vendor` directory is
forbidden because Composer derives its PSR-4 base from the resolved vendor path.

Run stratified directories in parallel on Windows with:

```powershell
tools/run_historical_pairing_scan.ps1 -MaxFilesPerDirectory 5
```

The result categories are exact, same-pairs-colour-variant, pairing-deviation,
engine-no-solution and engine-resource-limit. A deviation is not automatically
a rules violation: it needs comparison of the complete FIDE quality vector,
using the official local Gacrux pairing checker as a development oracle. Until
that comparison and the full decoded corpus replay pass, the project must not
claim that the pairing engine is verified against the number of TRFs accepted
by the parser.

The first bounded replay found a first-round colour bug when the highest TPN
was absent; it is covered by a regression test. It also demonstrated that the
current exhaustive bracket enumerator reaches its 250,000-node/8,192-candidate
limit frequently from 30 players upward. This is the present gate blocker.

### Pre-epoch-2 diagnostic history (invalid as gate evidence)

The following figures were measured with the old PAB-float semantics. The
100-tournament, seven-player sample contains 500 reconstructed rounds: 426
are exact and 74 deviate. Every deviation was analyzed with the official local
Gacrux `pairingchecker.py -c -a -p`; all 74 are inferior to its deterministic
optimum. First differences are QC14 27, HO1 12, QC12 10, QN8 10, QC6 8, QC8 5,
QC13 1 and QC15 1. There are therefore no certified equivalent variants in
this sample. The machine-readable evidence is generated by the development-only
`tools/oracle/gacrux_quality_classifier.py` and includes C6-C21 plus HE/HO/IW.

After porting the exact HE1-HE2 and HO1-HO5 local bracket order vectors, the
same frozen sample produces 441 exact rounds and 59 deviations, with no engine
failure or resource limit. The remaining first-difference distribution is
QC14 27, QC12 11, QC6 8, QC8 5, QN8 4, HO1 2, QC15 1 and QC13 1. The two
nominal HO1 cases both select a different PAB, so they are not local HO-order
defects. This establishes the intended enumerator checkpoint: no residual HE
or HO defect exists with a fixed local bracket; the unresolved results require
global round/PAB quality comparison. Evidence is in
`build/historical-n5-100-he-ho.json` and
`build/gacrux-quality-n5-100-he-ho.json` when generated in a working tree.

The independent polynomial Blossom core passes the strengthened sequential and
sparse-topology correctness gate, but Dutch is intentionally not wired to it
yet. Rule-weight completion
and matcher integration are both required before the full corpus replay.

## Preliminary whole-round graph audit

The optional `--preliminary-global` argument runs the new Blossom whole-round
completability/PAB-score pass alongside, but not instead of, the bounded
production engine:

```console
php tools/verify_trf_pairings.php DIRECTORY MAX_FILES --preliminary-global
```

The development-only complete-vector path can be observed without replacing
the production engine:

```bash
php tools/verify_trf_pairings.php DIRECTORY MAX_FILES --shadow-global
python3 tools/oracle/gacrux_quality_classifier.py \
  --sample-set shadow-oracle --upstream UPSTREAM --report REPORT --output OUTPUT
```

The shadow report includes all five comparison buckets, mandatory Gacrux input
for `same-deviation` and `divergent-deviation`, and per-bracket timing samples
and aggregates. See `ORACLE-FIRST-METHOD.md` for why a first-difference QC12 is
not a diagnosis of `ColourAllocator`.

On the frozen 100x7 sample it completed all 500 rounds and selected the correct
PAB score group in all 396 rounds containing a reference PAB. It selected the
same player in 341; the remaining 55 differ only within that score group. This
is the expected boundary of the preliminary pass: C9 is legal only when the PAB
is the single downfloater of the bracket, and the later persistent-bracket
refinement selects the assignee. Applying an unconditional unplayed-game
penalty at this stage would be a rules error. Evidence is generated as
`build/historical-n5-100-global-preliminary-final.json`. The frozen portion has
corpus manifest SHA-256
`e05981129109020ff5b35a77d972b3c36887823399dc2f5b7fd0e80d8b8bad44`.

The 55 within-group alternatives were then converted into complete
counterfactual rounds that preserve every reference board except the PAB's
board, and classified with Gacrux. All 55 are inferior. Their first differences
are QC12 32, QC9 16, QC14 2, and HE2, QC17, QN8, QC16 and QC15 one each. This
oracle result requires the complete persistent weight cascade and rejects a
C9-only implementation. The classifier copies the source report's runtime and
corpus identities into its own output.
The corresponding evidence is
`build/gacrux-preliminary-bye-55-final.json`.

The 256-player ceiling is both a parser/domain guard-rail and an explicit
initial-engine check. More importantly, the current practical Dutch limit is
lower because bracket enumeration grows combinatorially. Raising the guard-rail
alone would therefore be misleading; a scalable weighted general matcher and
candidate-generation strategy must come first.
