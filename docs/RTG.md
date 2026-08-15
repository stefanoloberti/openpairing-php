# Random Tournament Generator (RTG)

`bin/openpairing-rtg.php` is the public, free and offline random tournament
generator for OpenPairingPHP. It creates a complete TRF26 tournament by using
the selected embedded engine for every round and a stable Park-Miller random
sequence for results.

```console
php bin/openpairing-rtg.php --players=80 --rounds=9 --seed=20260815 > tournament.trf
php bin/openpairing-rtg.php --players=40 --rounds=7 --seed=42 --system=burstein --top-colour=B > burstein.trf
php bin/openpairing-rtg.php --players=80 --rounds=9 --seed=43 --baku --draw-permille=350 > accelerated.trf
```

The parameters are:

- `--players`: 2 through 4,096;
- `--rounds`: 1 through the smaller of 256 and players minus one;
- `--seed`: 1 through 2,147,483,646;
- `--system`: `dutch` (default) or `burstein`;
- `--top-colour`: `W` (default) or `B`;
- `--draw-permille`: deterministic draw probability, 0 through 1,000,
  default 300;
- `--baku`: apply the FIDE Baku virtual-point schedule and emit TRF26 `250`
  records.

The same version, parameters and seed produce byte-identical TRF output.
Generated files include the seed in record `182`, use record `202 BH` for a
reproducible final rank, and contain a non-empty pairing history. Unsupported,
incomplete or impossible configurations fail closed with exit code `3`.
Infrastructure failures use exit code `2`.

The RTG is an acceptance-data generator, not an independent oracle and not a
FIDE certification. Its output can be checked immediately with the public PTC:

```console
php bin/openpairing-ptc.php tournament.trf
```

The distributed deterministic control is
`examples/rtg-p8-r3-seed20260815.trf`. It is 1,160 bytes with SHA-256
`f7bdaf8164a38d7cb73f682490c8a0fefe5c8b5cd80aa5d2f6ad994c2bc3ad79`
and is reproduced by:

```console
php bin/openpairing-rtg.php --players=8 --rounds=3 --seed=20260815 --system=dutch --top-colour=B --draw-permille=300
```

Two independent invocations must be byte-identical. Passing that fixture to
the PTC produces `examples/rtg-p8-r3-seed20260815-ptc-report.json`, with three
exact rounds and an exact declared ranking.
