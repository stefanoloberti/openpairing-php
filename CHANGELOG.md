# Changelog

## 0.9.0-rc3 - 2026-08-13

- align the downloadable documentation with the acceleration support already
  shipped in rc2: TRF `250` import/export and legacy `XXA` import are complete;
- state tie-break coverage unambiguously as 21 base individual/TRF codes and
  more than 50 canonical option combinations, rather than an arbitrary count;
- no pairing, standings or format algorithm changed from rc2.

## 0.9.0-rc2 - 2026-08-13

- import/export round-indexed TRF `250` acceleration schedules and import
  legacy per-player `XXA` schedules with fail-closed conflict checks;
- raise the public player safety guard-rail from 256 to 4,096 after real
  2,400-player TRF round-trip and initial-round capacity checks;
- harden physical isolation of content-addressed replay workspaces.

## 0.9.0-rc1 - 2026-08-13

First downloadable release candidate. The public Dutch engine is exact on the
frozen 7/30/50/80-player gates, the runtime supports GMP with a bit-identical
pure-PHP fallback, and pairing plus individual standings are available through
the library and offline CLI. The full 122,008-tournament pairing replay remains
open, so this release is not presented as a verified 1.0 or as a substitute for
arbiter review.

## Unreleased

### Added

- Oracle-first method record, canonical complete Dutch QC/HE/HO quality model,
  shadow oracle policy and per-bracket rematch timings.

- Official-source provenance, licences, hashes and architecture ADRs.
- Strict PHP 8.3+ domain model, exact scores, canonical JSON v1 and CLI.
- FIDE Dutch 2026 bounded multi-round engine with C1-C21 and colours.
- FIDE Burstein 2026 engine with Dutch seeding, BH/SB/TPN ranking, C1-C8,
  BSN ordering, colours and completion-aware PAB.
- Pure-PHP bounded exact weighted general-graph matcher and property tests.
- Fail-closed TRF-2026 individual Dutch/Burstein reader and writer, plus Round
  Robin import/export for standings.
- Complete mandatory FIDE 2026 individual tie-break calculator/ranker,
  Cut/Median/Limit and P/F variants, exact rating tables, Article 16 handling,
  TRF 202/212 descriptors, standings rows and CLI mode.
- FIDE 2026 Baku acceleration calculator and real-plus-virtual pairing scores
  in Dutch and Burstein scoregroups, ordering and board sorting.
- Independent state/result validation, Gacrux/BBP development adapters,
  differential classifier, deterministic corpus and benchmark command.
- Machine-readable up/down float assignments with independent validation.
- Historical TRF round-replay verifier and parallel corpus launcher; fixed
  first-round colour alternation when the highest TPN is absent.
- Pure-PHP polynomial weighted Blossom core with arbitrary-width exact weights,
  BBP provenance, 1,000-graph exhaustive differential gate and 300-vertex stress
  coverage. At that historical checkpoint Dutch integration still awaited its
  complete quality function; the later public-engine item below records its
  completion.
- Exact Dutch HE1-HE2 and HO1-HO5 local bracket ordering, including a minimized
  Gacrux regression; the frozen 100x7 replay improved from 426/500 to 441/500
  exact rounds and has no residual fixed-bracket HE/HO deviation.
- Fail-closed runtime identity attestation for historical replay: resolved
  Composer root, engine/source hashes, immutable workspace lock and homogeneous
  identity enforcement in parallel aggregates.
- ADR for Dutch integration through one persistent whole-round matching graph,
  including BBP's preliminary PAB pass and bracket reweight/finalize lifecycle.
- Isolated Dutch whole-round compatibility graph and Blossom preliminary pass;
  the frozen 100x7 audit proves 500/500 completability and 396/396 PAB score
  groups without altering production pairings.
- Deterministic corpus/portion identity in historical reports and aggregates,
  covering relative paths, byte lengths and SHA-256 of every raw TRF.
- Executable Dutch freeze-lookahead invariant with regressions for a locally
  tied but globally inferior choice and an incomplete residual graph; Gacrux
  classification of all 55 preliminary PAB counterfactuals.
- Shadow comparison categories and a release-blocking candidate-regression
  distinction, ready for the complete global Dutch engine before switchover.
- Public Dutch engine backed by the persistent whole-round matcher, with the
  bounded Dutch engine retained as a small-field test oracle.
- Runtime-selectable GMP arbitrary-width arithmetic with bit-identical limb
  fallback, backend attestation, deterministic unsigned field packing and
  1,000-sequence state-by-state differential coverage.
- Proven-optimal residual matching reuse after unchanged-weight freezes,
  guarded by fresh-Blossom and exhaustive sequential stress tests.
- Direct-reference large-field protocol that exercises the public engine when
  the bounded legacy oracle reaches its resource limit.

### Known limits

- Burstein pairing search remains explicitly bounded; Dutch uses the
  polynomial persistent matcher in production.
- TRF team and advanced control records are outside the documented subset.
- The complete 1,000-tournament accelerated *pairing* replay remains open.
  TRF `250` is supported for import/export and legacy `XXA` is supported on
  import with canonical normalization to `250`.
- The historical pairing corpus gate is not yet passed. The public Dutch
  engine is exact on frozen 7/30/50/80-player samples; the complete campaign
  remains open.
- Team, team-knockout and team-score tie-break variants remain phase 2.
