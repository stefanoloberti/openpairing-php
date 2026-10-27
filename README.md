# OpenPairingPHP

[![CI](https://github.com/stefanoloberti/openpairing-php/actions/workflows/ci.yml/badge.svg)](https://github.com/stefanoloberti/openpairing-php/actions/workflows/ci.yml)

OpenPairingPHP is an independent, deterministic and offline FIDE pairing
library and CLI for PHP 8.3+. It is a new project, not a modification of Clever
Chicken, and it does not implement USCF pairing rules.

The project is a release candidate. It is **not certified, endorsed or
approved by FIDE**; tournament arbiters remain responsible for checking and
publishing pairings.

## Implemented

- FIDE Dutch 2026 initial and multi-round score-bracket pairing, including
  MDPs, downfloats, C1-C21 quality comparison, colour allocation and PAB;
- FIDE Burstein 2026: Dutch seeding rounds, BH/SB/TPN bracket ranking,
  C1-C8, BSN ordering, colours and completion-aware PAB;
- exact integer/rational scoring without binary floating-point decisions;
- FIDE Baku acceleration schedule and pairing-score handling for Dutch and
  Burstein, including an immutable late-entry-aware state transformer;
- persistent whole-round Dutch matching in the public engine, with the bounded
  implementation retained as an independent small-field regression oracle;
- an isolated pure-PHP polynomial weighted Blossom core with arbitrary-width
  exact weights;
- canonical JSON v1 and fail-closed TRF-2026 individual read/write for Dutch
  and Burstein, including scheduled individual byes (`240`), round-indexed
  acceleration (`250`) and round-ranged prohibited pairings (`260`);
- structured diagnostics, violations, quality vectors and deterministic trace;
- machine-readable up/down float assignments validated against pairing scores;
- independent tournament and pairing-result validation;
- complete FIDE individual standings layer effective 1 March 2026: all 17
  mandatory tie-break methods, plus STD, TPN, RTNG and the TRF `PTS` ordering
  key; all required Cut/Median/Limit and P/F variants, rating tables and Swiss
  unplayed-round management;
- development-only offline Gacrux and BBP adapters, status separation and
  deterministic differential fixtures.

The production namespace has no Python, Gacrux, BBP, process, database,
framework, network or service dependency. The upstream programs are only
development oracles.

## Explicit limits

The public Dutch path uses the polynomial Blossom core and no longer has the
legacy 250,000-node/8,192-candidate guard-rail. Burstein retains bounded
exhaustive search, so difficult Burstein brackets can still return a
machine-readable resource-limit result without a partial pairing. Canonical
JSON and TRF currently enforce a 4,096-player/256-round input safety ceiling.
Canonical JSON and TRF record `250` support round-indexed virtual points, and
the engines apply them to scoregroups, internal order and board order. Legacy
per-player acceleration record `XXA` is accepted on import and normalized to
`250`. Generic individual bonus/penalty record `299`, team records and team
tie-breaks remain outside the documented individual product. See `docs/ACCELERATION.md`,
`docs/TRF-2026.md`, `docs/TIE-BREAKS.md` and ADR 0003.

## Install and verify

**Documentation:** [English user manual](docs/USER-MANUAL.md) ·
[documentation index](docs/README.md) · [CLI reference](docs/CLI.md) ·
[verification method](docs/ORACLE-FIRST-METHOD.md)

```console
composer install
composer check
composer benchmark
```

For production use, install PHP 8.3+ and enable `ext-gmp`; the engine selects
the native backend automatically. Without GMP it remains correct and uses the
bit-identical pure-PHP 30-bit-limb fallback, which is substantially slower on
large fields. A prebuilt runtime archive contains the optimized Composer
autoload files and can be unpacked and invoked directly with
`php bin/openpairing.php`.

Oracle tests require the ignored local upstream checkouts plus Python/WSL and
are intentionally separate: `composer test-oracles`. Ordinary
`composer check` verifies production code offline without them.

## CLI

Canonical JSON is the default input:

```console
php bin/openpairing.php tests/Fixtures/initial-3.json
```

TRF input can be selected explicitly or inferred from a `.trf` suffix:

```console
php bin/openpairing.php --input-format=trf tournament.trf
```

The result is machine-readable JSON. See `docs/CLI.md`.

Final standings can be calculated from TRF `202`/`212` metadata or an explicit
descriptor list:

```console
php bin/openpairing.php --standings tournament.trf
php bin/openpairing.php --standings --tie-breaks=DE/P,BH/C1/P,SB/C1 state.json
```

The free offline Pairings and Tie-Breaks Checker replays a completed TRF26 tournament and
checks its declared final ranking:

```console
php bin/openpairing-ptc.php examples/ptc-completed-round-1.trf
```

It produces a machine-readable `openpairing.ptc-report/1` report. See
`docs/PTC.md`.

The free offline RTG creates reproducible complete TRF26 acceptance inputs:

```console
php bin/openpairing-rtg.php --players=80 --rounds=9 --seed=20260815 > tournament.trf
```

Dutch, Burstein, both initial colours and Baku acceleration are selectable.
See `docs/RTG.md`.

The complete English operating guide is `docs/USER-MANUAL.md`. The frozen
package reproduction procedure and its explicit THP boundary are in
`docs/CONTROLLED-ENVIRONMENT.md`.

The intended 1.0 compatibility surface is inventoried in
`docs/API-STABILITY.md`; implementation matching classes are not public API
merely because PHP visibility permits instantiation. Composer/Packagist release
controls are in `docs/PACKAGING.md`.

## Library

```php
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Format\Json\CanonicalJson;

$state = (new CanonicalJson())->decode($json);
$result = (new BursteinPairingEngine())->pair($state);
```

Tie-break ranking uses an ordered list and preserves unresolved tied groups:

```php
use OpenPairing\Domain\TieBreak\FideTieBreakCode;
use OpenPairing\Domain\TieBreak\FideTieBreakRanker;
use OpenPairing\Domain\TieBreak\TieBreakDefinition;
use OpenPairing\Domain\TieBreak\TieBreakModifier;

$ranking = (new FideTieBreakRanker())->rank($state, [
    new TieBreakDefinition(FideTieBreakCode::DirectEncounter),
    new TieBreakDefinition(FideTieBreakCode::Buchholz, TieBreakModifier::Cut1),
    new TieBreakDefinition(FideTieBreakCode::SonnebornBerger),
]);

// Or consume the exact TRF 202/212 descriptor list in canonical state:
$ranking = (new FideTieBreakRanker())->rankFromTournamentRecord($state);
$rows = $ranking->standings($state);
```

## Verification

| Gate | Current recorded status |
|---|---|
| Rapid offline suite / static analysis | PASS for RC8; exact counts are recorded in the RC8 evidence file |
| GMP versus pure-PHP limbs | PASS on deterministic stress sequences; CI locks and proves both backends |
| Historical p7-misc | PASS with qualified evidence: 122,008 tournaments; 2,220,576/2,220,579 reference-exact rounds and 3 classified misc deviations |
| Accelerated `250` corpus | PASS under the separate epoch-5 virtual-float identity: 1,000 tournaments, 11,000/11,000 reference-exact rounds; immutable aggregate and independent receipt |
| Larger-field capacity corpus | OPEN: 547 separate tournaments above the former 256-player limit |
| Public PTC/RTG controls | PASS for deterministic positive, negative and round-trip fixtures |
| Complete THP/TAPC product | NO-GO: product-level requirements remain open |

Independent validators check player accounting, self/repeat pairings, C3,
colour priority, bye eligibility, histories, reciprocal results and exact
scores before differential output is classified. Oracle comparisons distinguish
exact, colour variant, legitimate variant, oracle deviation, local violation,
invalid inputs and infrastructure/resource failures. Reproduction data belongs
in `tests/Regression` with schema, input hash, upstream versions and fixed seed.
The coverage map and reproduction commands are in `docs/CORPUS.md`.

The future product-level observer and its non-mutating acceptance categories
are specified in `docs/SHADOW-VALIDATION.md`. It is not evidence until an exact
ChessPairings/OpenPairing candidate is selected and observed.

The 123,557-file external TRF scan is parser evidence only; pairing evidence
is recorded separately. The 122,008 decodable historical tournaments have
closed replay evidence, including three qualified misc deviations. The
separate 1,000-tournament acceleration replay is also closed under its own
identity. Together those two scopes contain 123,008 verified tournaments and
2,231,576/2,231,579 reference-exact rounds, with three classified misc
deviations. They do not close the 547 larger-field capacity tournaments or the
ChessPairings/FIDE product gate. See `docs/CORPUS-GATE.md` and
`docs/HISTORICAL-PAIRING-VERIFICATION.md` for exact evidence and limits.

## Provenance and licence

The project is Apache-2.0 and the runtime implementation is original code
informed by published rules. Development references are official FIDE/TEC
publications, [Gacrux/TieBreakServer](https://github.com/OttoMilvang/TieBreakServer)
(MIT) and [BBP Pairings](https://github.com/BieremaBoyzProgramming/bbpPairings)
(Apache-2.0). The Gacrux notice and requested
[www.gacrux.no](https://www.gacrux.no/) link are retained in `NOTICE`.

See `docs/UPSTREAM.md`, `docs/LICENSE-PROVENANCE.md` and
`docs/PORTING-MAP.md`. Licence notes are technical assessments, not legal
advice.
