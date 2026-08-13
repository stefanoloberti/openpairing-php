# Release 0.9.0-rc1

## Artifacts

The release process produces two archives from one committed tree:

- `openpairing-php-0.9.0-rc1-source.zip`: source, tests, development tools and
  Composer lock file; run `composer install` before use;
- `openpairing-php-0.9.0-rc1-runtime.zip`: source plus optimized production
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

This is a release candidate, not a FIDE certification. The public Dutch path
has exact attested reference gates at 7, 30, 50 and 80 players. The complete
122,008-tournament historical pairing campaign is still running; the
256-player/256-round format guard-rail, TRF acceleration record 250/XXA and
team pairing/tie-break functions remain documented limits.
