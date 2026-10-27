# Composer and distribution readiness

The Composer package name is `openpairing/openpairing-php`. Metadata, PSR-4
autoloading and the three binary entry points are prepared for Packagist, but
the package has not been declared published by this repository and no stable
tag should be created while the submission ledger is NO-GO.
The latest non-published reproducibility and redacted-secret-scan dry run is
recorded in `evidence/release-dry-run-b30d3e3-20260816.json`; it keeps the
stable-release gate open because it is neither a frozen THP nor a non-RC tag.

## Pre-publication gate

1. `composer validate --strict` passes on the exact committed tree.
2. CI passes on PHP 8.3 and 8.4 with explicitly locked GMP and limb backends.
3. `tools/build_release.ps1` reproduces byte-identical source/runtime archives.
4. The runtime archive contains the supported API and public PTC/RTG controls,
   and contains no development oracle or corpus.
5. The exact THP source, installer and extracted runtime have no unresolved
   credential findings. Run `php tools/scan_distribution_secrets.php ROOT` as
   a value-redacting heuristic preflight; a finding requires private owner
   review and credential rotation, while a PASS does not replace manual review.
   The repository-level scan is also part of `composer check` and therefore of
   every PHP/backend CI cell; extracted installers and archives still require
   their separate release-time scans.
6. `API-STABILITY.md`, licence/provenance, changelog, support links and the
   limitations statement match the tag.
7. A non-RC version is selected only after the applicable corpus, acceleration,
   product and controlled-environment gates close.
8. The Git tag, GitHub release, archive checksums and Packagist version all
   identify the same commit. Packagist publication remains an explicit account
   action and is not performed by the automated campaign steward.

Suggested consumer command after an actual stable publication:

```console
composer require openpairing/openpairing-php:^1.0
```

Until then, consumers should use a recorded release-candidate archive and its
published SHA-256 rather than assuming a Packagist version exists.
