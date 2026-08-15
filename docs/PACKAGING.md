# Composer and distribution readiness

The Composer package name is `openpairing/openpairing-php`. Metadata, PSR-4
autoloading and the three binary entry points are prepared for Packagist, but
the package has not been declared published by this repository and no stable
tag should be created while the submission ledger is NO-GO.

## Pre-publication gate

1. `composer validate --strict` passes on the exact committed tree.
2. CI passes on PHP 8.3 and 8.4 with explicitly locked GMP and limb backends.
3. `tools/build_release.ps1` reproduces byte-identical source/runtime archives.
4. The runtime archive contains the supported API and public PTC/RTG controls,
   and contains no development oracle or corpus.
5. `API-STABILITY.md`, licence/provenance, changelog, support links and the
   limitations statement match the tag.
6. A non-RC version is selected only after the applicable corpus, acceleration,
   product and controlled-environment gates close.
7. The Git tag, GitHub release, archive checksums and Packagist version all
   identify the same commit. Packagist publication remains an explicit account
   action and is not performed by the automated campaign steward.

Suggested consumer command after an actual stable publication:

```console
composer require openpairing/openpairing-php:^1.0
```

Until then, consumers should use a recorded release-candidate archive and its
published SHA-256 rather than assuming a Packagist version exists.
