# OpenPairingPHP

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
  and Burstein;
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
JSON supports virtual points and the engines apply them to scoregroups,
internal order and board order; TRF
record `250`, team/prohibited-pair records and team tie-breaks remain outside
the documented individual product. See `docs/ACCELERATION.md`,
`docs/TRF-2026.md`, `docs/TIE-BREAKS.md` and ADR 0003.

## Install and verify

```console
composer install
composer check
composer benchmark
```

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

Independent validators check player accounting, self/repeat pairings, C3,
colour priority, bye eligibility, histories, reciprocal results and exact
scores before differential output is classified. Oracle comparisons distinguish
exact, colour variant, legitimate variant, oracle deviation, local violation,
invalid inputs and infrastructure/resource failures. Reproduction data belongs
in `tests/Regression` with schema, input hash, upstream versions and fixed seed.
The coverage map and reproduction commands are in `docs/CORPUS.md`.

The 123,557-file external TRF scan is parser evidence only: 122,008 legacy
files were decoded, but that number must not be presented as pairing-engine
verification. Historical round replay is a separate gate. Frozen
7/30/50/80-player samples are exact; the full 122,008-tournament campaign is
still open. See `docs/HISTORICAL-PAIRING-VERIFICATION.md`.

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
