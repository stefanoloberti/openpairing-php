# Milestone 1 report

> Historical report: counts and limitations below describe the first vertical
> slice only. Current capabilities and verification are in `README.md`,
> `FUNCTIONAL-MATRIX.md` and `CORPUS.md`.

Completed locally on 2026-08-11. No commit, remote repository, push, account,
credential write, operational site, tournament, watchdog, runner, or protected
USCF engine was touched.

## Evidence

- `composer validate --strict`: valid.
- `composer check`: PHPUnit 13 tests / 42 assertions, all passing; PHPStan level
  max, no errors.
- Differential coverage: 2, 4, 5, 6 and 9 players; both initial colours; exact
  opponents, colours and bye against Gacrux commit `14a34a2...`.
- `composer audit --locked`: no known security vulnerability advisories.
- PHP lint: every PHP source, test, adapter and CLI file passed.
- CLI valid fixture: exit 0 with no independent validation violation.
- CLI invalid document: structured `json.string` diagnostic, exit 3.
- Gacrux and BBP acquisition working trees: clean after all tests.
- Apache-2.0 project `LICENSE`: whitespace-normalised text is word-for-word
  equivalent to BBP's full `Apache-2.0.txt`.

## Functional coverage

Implemented: separate Composer project, exact scores, minimum domain history,
canonical JSON v1 round trip, central engine interface, structured result and
diagnostics, first Dutch round, even/odd populations, PAB, configurable initial
colour, deterministic trace, independent structural validator, CLI, local
Gacrux adapter, regression manifest, provenance/licence documentation and ADR.

The five differential examples are `exact`; no regulatory divergence was found.
One harness-only discrepancy was found and fixed: Gacrux pairs had initially
been flattened in internal bracket order instead of their explicit board order.

## Open risks and next phase

- The pairing algorithm intentionally rejects every round after round 1.
- Independent validation does not yet cover repetitions, colour preference
  strength, scoregroup compatibility or float quality.
- TRF-2026 parsing/writing is not implemented.
- The Gacrux adapter supports only the initial-round subset.
- BBP oracle/matching, full Dutch, Burstein, tie-breaks and benchmarks remain.
- The licence assessment needs legal review before distributing substantial
  semantic ports.

Recommended next: implement the validated canonical result-history model and a
small fail-closed TRF-2026 reader/writer subset, then expand the Gacrux adapter
and Dutch engine to a controlled two-scoregroup second-round corpus. Do not
start BBP weighted matching until those boundaries and comparisons stabilise.
