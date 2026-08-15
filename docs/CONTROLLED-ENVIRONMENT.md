# Controlled verification environment

This procedure reproduces the public-package checks without a database,
network service or upstream oracle. It is an engine-package environment, not
yet the complete TEC tester environment for a THP.

## Frozen inputs

For the recorded RC7 public-tools gate:

- Git commit: `f12f0a7b8bf803119e3c15c8cd688ac6fd4b454e`;
- source archive SHA-256:
  `83e17f771442db858b2564a53ca01cf123189a8062ae8b2eaf52417a93eb617e`;
- runtime archive SHA-256:
  `a404b5159adf7487efc8fbc6f3f605e50d605f46d06d5ac9dcc41f1681f0c2b3`;
- build evidence: `evidence/rc7-public-tools-gate-20260815.json`.

For the recorded RC8 TRF26/campaign gate:

- product commit: `86ec9d9c003d68468906bddf12c69fbcaaf750ac`;
- source archive SHA-256:
  `68a17c06109e42146183ec5e91fddb32e21d2df0b031d36eed5f7cc021677b4f`;
- runtime archive SHA-256:
  `cf34b82bbcfa5551cf98e3ac8e11e8f6880d70e0745f82ea5853e9dbf81517e4`;
- campaign source tree SHA-256:
  `a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884`;
- campaign harness SHA-256:
  `a617aa2677cdf3d0ad204caa73b18d6dceceb61b1d2d65ba95f9669a8ab06c4c`;
- two independent release builds were byte-identical;
- build/campaign evidence:
  `evidence/rc8-trf26-and-p80-realignment-20260815.json`.

The builder was reproduced twice from the same commit and both ZIP files were
byte-identical. The source and runtime archives are distinct deliverables and
must not be interchanged.

## Runtime profiles

The public runtime contract is PHP 8.3+ on a commercially available 64-bit
operating system. Two arithmetic profiles are deliberately checked:

1. Windows 64-bit PHP 8.4.24 without GMP, exercising the exact pure-PHP limb
   fallback;
2. Ubuntu 24.04 under WSL, PHP 8.3 with GMP, exercising the production
   large-field backend.

Both profiles use the CLI SAPI. Network access is unnecessary after the
runtime ZIP is present. The official corpus campaign additionally attests its
PHP, GMP, OPcache/JIT, source-tree, engine and harness identities in
`CORPUS-GATE.md`.

## Reproduction

Create a fresh directory, copy only the runtime ZIP and checksum into it, and
verify the digest with the operating-system SHA-256 utility. Extract the ZIP,
enter its single top-level directory and run:

```console
php bin/openpairing.php --version
php bin/openpairing.php --help
php bin/openpairing.php examples/pairing-round-1.json
php bin/openpairing.php --standings examples/standings-round-2.json
php bin/openpairing-ptc.php examples/ptc-completed-round-1.trf
php bin/openpairing-ptc.php examples/ptc-colour-variant.trf
php bin/openpairing-rtg.php --players=8 --rounds=4 --seed=42 --baku > rtg.trf
php bin/openpairing-ptc.php rtg.trf
```

Expected outcomes:

- version/help/pairing/standings and the positive PTC exit `0`;
- the negative PTC exits `1`, reports `discrepancy` and exactly one
  `roundsSamePairsColourVariant`;
- two RTG runs with the same options are byte-identical;
- the RTG-to-PTC report exits `0` with four exact rounds.

`tools/build_release.ps1` performs these checks against the extracted runtime,
audits forbidden development paths and validates the production Composer
metadata. An official final candidate must repeat this procedure from its own
non-RC commit and record new hashes; recorded RC hashes must never be reused.

## Evidence boundary

This environment proves that the delivered engine package is internally
coherent and reproducible. It does not prove rating-list import, GUI FIDE mode,
result-entry workflow, recovery or other complete-THP requirements. Those
must be exercised on the exact submitted ChessPairings binary or on another
product scope accepted in writing by TEC.
