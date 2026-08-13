# Release 0.9.0-rc6

## Artifacts

The release process produces two archives from one committed tree:

- `openpairing-php-0.9.0-rc6-source.zip`: source, tests, development tools and
  Composer lock file; run `composer install` before use;
- `openpairing-php-0.9.0-rc6-runtime.zip`: source plus optimized production
  Composer autoload files, ready for the CLI or library.

Every archive is accompanied by a `.sha256` file. The runtime archive contains
no Gacrux, BBP, Python, network service, framework or database dependency.

Build both archives from a clean committed tree with:

```powershell
tools/build_release.ps1
```

The builder creates a production-only optimized Composer autoloader, normalizes
runtime ZIP timestamps to the Git commit time, audits forbidden development
paths, runs CLI and pairing smoke tests, and writes a machine-readable build
report beside the archives. The runtime `composer.json` is stripped of
development dependencies, test autoloading and laboratory scripts.
The source development lock file is omitted from the prebuilt runtime so it
cannot conflict with that production-only metadata.

## Requirements

- PHP 8.3 or later;
- `ext-gmp` strongly recommended for medium and large fields;
- no extension beyond the documented PHP baseline is required for the
  bit-identical pure-PHP arithmetic fallback.

Unpack the runtime archive and check the CLI:

```console
php bin/openpairing.php --help
php bin/openpairing.php tournament.trf
php bin/openpairing.php --standings tournament.trf
```

## Release status

This is a release candidate, not a FIDE certification. RC6 corrects
historical-float ageing across unplayed tournament rounds. Its seven-player
gate is reconfirmed at 500/500 exact, and the p50 campaign is being replayed
under the corrected identity. The complete historical pairing campaign
previously closed p7, p9, p15 and p30 at 69,998 tournaments and 609,874 exact
rounds; a uniform-identity replay remains required before those partitions are
combined with RC6 in a final claim. No complete-corpus claim is made.
Separate capacity gates cover the 547 files newly admitted above the former
256-player limit. The
4,096-player/256-round format guard-rail and team pairing/tie-break functions
remain documented limits. TRF `250` import/export and legacy `XXA` import are
supported; the full accelerated pairing-corpus gate remains open.
