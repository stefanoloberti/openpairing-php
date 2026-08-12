# Reproducible corpus

The ordinary PHPUnit corpus is deterministic and offline. Programmatic cases
use fixed TPN order and the named deterministic result policy in
`DeterministicTournamentSimulator`; they do not use ambient randomness.
Oracle-backed regressions additionally record canonical input files, SHA-256,
schema and pinned upstream commits in `tests/Regression/manifest.json`.

The large external TRF corpus has separate parser and historical-pairing
reports. Successful decode or round-trip is not counted as engine verification.
Use `tools/verify_trf_pairings.php` and the parallel launcher documented in
`HISTORICAL-PAIRING-VERIFICATION.md` for the pairing gate.

| Required area | Evidence |
|---|---|
| 2-128 players, even/odd | `LargeInitialCorpusTest` |
| early and deep rounds | initial tests, `DeepSyntheticCorpusTest`, Burstein post-seeding tests |
| equal ratings and unrated | `ScenarioCorpusTest::testEqualRatingsAndUnratedPlayersRemainDeterministic` |
| imbalanced scoregroups and floats | `DutchPairingEngineTest` round-two cases |
| consecutive/strong/absolute colours | `DutchRulesTest`, `ColourAllocatorTest`, `BursteinRulesTest` |
| repeats near inevitable / exhausted | four-player round-robin and explicit no-solution cases |
| previous PAB and requested byes | Dutch PAB, result validator and unplayed-round scenario tests |
| withdrawn players | `ScenarioCorpusTest::testWithdrawnPlayersAreExcludedFromRoundAccounting` |
| acceleration | Baku schedule, canonical round-trip and two-engine first-round tests |
| non-standard scoring | 3-1-0 two-engine scenario and exotic tie-break scoring test |
| malformed input | canonical JSON and TRF fail-closed tests |
| all individual FIDE standings methods | `FideTieBreakCalculatorTest` 20-code matrix, descriptor families, rating-table and `/P`, `/F`, `/L` cases |
| Gacrux tie-break parity | pinned four-player values in `tests/Regression/gacrux-individual-tiebreak-round-robin.json` for BH/FB/AOB/KS and all rating-performance families |
| stress and resource limits | 128-player corpus, 256-player benchmarks, Burstein matcher-limit test |
| oracle timeout/infrastructure | adapter status model and isolated `oracle` test group |

Run the production corpus with `composer test`, static analysis with
`composer analyse`, benchmarks with `composer benchmark`, and the local pinned
oracle corpus (when Python/WSL prerequisites exist) with
`composer test-oracles`. A new differential disagreement must be minimized and
added to the regression manifest with both outputs, diagnostic category and
upstream version; infrastructure failures are never recorded as rule verdicts.
