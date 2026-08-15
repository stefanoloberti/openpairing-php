# Release 0.9.0-rc8

## Artifacts

The release process produces two archives from one committed tree:

- `openpairing-php-0.9.0-rc8-source.zip`: source, tests, development tools and
  Composer lock file; run `composer install` before use;
- `openpairing-php-0.9.0-rc8-runtime.zip`: source plus optimized production
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
The extracted-runtime verification procedure is documented in
`CONTROLLED-ENVIRONMENT.md`; user operation and incident handling are in
`USER-MANUAL.md`.

The recorded RC8 artifacts are byte-identical across two clean builds. Their
SHA-256 values are
`68a17c06109e42146183ec5e91fddb32e21d2df0b031d36eed5f7cc021677b4f`
for the source archive and
`cf34b82bbcfa5551cf98e3ac8e11e8f6880d70e0745f82ea5853e9dbf81517e4`
for the runtime archive.

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
php bin/openpairing-ptc.php examples/ptc-completed-round-1.trf
php bin/openpairing-rtg.php --players=8 --rounds=4 --seed=42 > generated.trf
```

## Release status

This is a release candidate, not a FIDE certification. RC8 adds end-to-end
individual TRF26 records `240` and `260` to the RC7 public PTC/RTG and
fail-closed codec. Historical p7 through p50 are closed at 99,998
tournaments and 1,378,198 exact rounds; p80 and the later partitions remain
open and must run under an RC8-aligned immutable identity. No complete-corpus claim is
made. Separate capacity gates cover the 547 files above the former 256-player
limit. The 4,096-player/256-round format guard-rail and team pairing/tie-break
functions remain documented limits. TRF `250` import/export and legacy `XXA`
import are supported; generic individual bonus/penalty record `299` remains
fail-closed, and the full accelerated pairing-corpus gate remains open.
