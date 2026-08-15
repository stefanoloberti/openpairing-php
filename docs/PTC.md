# Pairings and Tie-Breaks Checker (PTC)

`bin/openpairing-ptc.php` is the public, free and offline Pairings and Tie-Breaks Checker
for OpenPairingPHP. It accepts a completed TRF26 individual tournament and
replays every populated round with the embedded Dutch or Burstein engine.
It also recomputes the final ranking when record `202` or `212` is present.

```console
php bin/openpairing-ptc.php tournament.trf
php bin/openpairing-ptc.php - < tournament.trf
php bin/openpairing-ptc.php --version
```

The JSON report uses schema `openpairing.ptc-report/1` and separates:

- exact rounds;
- identical pairs with different scheduled colours;
- pairing deviations;
- engine failures or unpaired competitors;
- exact or mismatching declared final ranks.

The formal JSON Schema is `docs/schema/ptc-report-v1.schema.json`.

Exit code `0` means every observed case is exact. Exit code `1` means the
checker completed and found a discrepancy, `3` means invalid or unsupported
input, and `2` is reserved for an infrastructure failure. A report with no
pairing round and no declared ranking fails closed instead of returning a
vacuous success.

The PTC invokes no network service or external pairing oracle. It checks the
installed OpenPairing engine itself and therefore does not constitute FIDE
certification or an independent proof that the engine is correct. It is the
public acceptance tool required to reproduce the product's own pairings and
tie-break rankings. The official corpus gates remain independent development
evidence.

Run the distributed example with:

```console
php bin/openpairing-ptc.php examples/ptc-completed-round-1.trf
```

Its expected machine-readable output is
`examples/ptc-exact-report.json`.

The distributed negative control must return exit code `1` and status
`discrepancy`:

```console
php bin/openpairing-ptc.php examples/ptc-colour-variant.trf
```

Its expected machine-readable output is
`examples/ptc-discrepancy-report.json`. These paired input/output fixtures make
both the success and discrepancy paths reviewable without interpreting console
prose.
