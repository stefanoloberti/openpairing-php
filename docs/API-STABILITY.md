# Public API and stability policy

This inventory defines the intended supported surface for the first stable
OpenPairingPHP library release. It is a compatibility contract, not a FIDE
approval claim. Release candidates may still change, but any change to this
inventory must be explicit in the changelog and must create a new candidate
identity.

## Supported library surface

| Area | Supported entry points | Contract |
|---|---|---|
| Pairing | `PairingEngineInterface::pair()`, `DutchPairingEngine`, `BursteinPairingEngine` | A valid immutable `TournamentState` produces one complete `PairingResult` or structured violations; never a partial success |
| Tournament model | `TournamentState`, `Player`, `ScheduledBye`, `ProhibitedPairingGroup`, `Colour`, `Score` | Constructor fields, enum values and exact rational scoring are serialized contracts |
| Results | `PairingResult`, `PairingTable`, `FloatAssignment`, `FloatDirection` | `toArray()` keys and algorithm-version strings are machine-readable contracts |
| Formats | `CanonicalJson::decode()/encode()`, `Trf2026Codec::decode()/encode()` | Canonical JSON schema `openpairing.tournament/1`; declared fail-closed TRF26 individual subset |
| Acceleration | `BakuAcceleration::apply()` | Round-indexed FIDE Baku virtual scores, including late-entry boundary |
| Tie-breaks | `FideTieBreakRanker`, `FideTieBreakCalculator`, `TieBreakDescriptorParser`, `TieBreakDefinition`, public tie-break enums and values | Descriptor parsing, exact values, unresolved tied groups and standings rows |
| Public controls | `TrfPairingChecker::check()`, `RandomTournamentGenerator::generate()` | PTC report schema `openpairing.ptc-report/1` and deterministic RTG contract `openpairing.rtg/1` |
| CLI | `bin/openpairing.php`, `bin/openpairing-ptc.php`, `bin/openpairing-rtg.php` | Documented options, exit classes and JSON/TRF outputs in `CLI.md`, `PTC.md` and `RTG.md` |

The supported input is the documented data model or one of the public codecs.
Constructing inconsistent internal objects and bypassing the validator is not
a supported use case.

## Internal implementation surface

PHP visibility alone does not make a symbol part of the compatibility
contract. The following namespaces and classes are implementation details and
may change in a minor release before 1.0:

- `OpenPairing\Engine\Dutch\GlobalMatching\*`;
- `OpenPairing\Engine\Matching\*`;
- `LegacyDutchPairingEngine`, preliminary/shadow coordinators and bounded
  search classes;
- `OpenPairing\Diagnostics\*`, except the documented arrays returned by the
  public entry points;
- development-only code below `tools/`, `tests/` and `OpenPairing\Development`.

Applications must not select a matching primitive, depend on search-event
ordering or instantiate a shadow/legacy engine. Those details are deliberately
outside the pairing API.

## Errors and determinism

- Invalid input throws `InvalidTournamentState` with a stable diagnostic code.
- Pairing impossibility or a declared resource limit is returned in
  `PairingResult::violations`; it is not converted into a partial pairing.
- The same supported state, algorithm version and arithmetic backend contract
  must produce the same pairing and result hash. GMP and the pure-PHP limb
  backend are required to be semantically identical.
- Trace text is diagnostic. Trace codes and structured result fields are the
  machine-consumable contract; prose may be clarified without a major release.

## Versioning rule

The package follows Semantic Versioning after 1.0. Before 1.0, every release
candidate freezes this table for its own evidence epoch. A breaking change to
the supported surface requires a new candidate, changelog entry, regenerated
runtime/source archives and repeat of the proportional gates. Algorithm
versions and schema versions change independently when their semantics change.

The final 1.0 gate must verify this inventory against the built runtime archive
and ensure no documented supported class was omitted from Composer autoloading.
`docs/schema/public-api-0.9.json` is the current machine-readable signature
manifest; `composer check-api` reflects the installed package and fails on any
undeclared signature drift.
