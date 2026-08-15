# TEC PTC/RTG cross-test evidence

TEC Manual 1.24 Article 3.9.4 describes two separate large-scale controls:
the candidate PTC processes 50,000 tournaments from an external available
RTG, and the candidate RTG produces 50,000 tournaments for checking by one or
more available PTCs. These controls are distinct from the historical TRF
corpus and from a candidate's self-round-trip.

For either direction, retain one strict batch manifest and one NDJSON event
stream. Their formats are
`schema/tec-cross-test-batch-1.schema.json` and
`schema/tec-cross-test-case-1.schema.json`. The manifest pins the exact
candidate product, package, engine, PTC and RTG hashes plus the counterpart
tool and its acceptance reference. Every case records opaque ID and hashes for
the input TRF and checker report; no player data leaves the controlled test
environment.

Run:

```console
php tools/verify_tec_cross_test.php batch.json cases.ndjson
```

The default minimum is 50,000. `--minimum-tournaments=N` is only for unit,
synthetic or staging qualification. Exit `0` means a mechanically complete
zero-discrepancy batch. Exit `1` means `NO_GO` or `REVIEW_REQUIRED`; exit `2`
means malformed, identity/hash or denominator infrastructure evidence.

The verifier follows a deliberately conservative boundary:

- any candidate-engine error is `NO_GO`;
- more than ten discrepancies is `NO_GO`;
- input errors attributed to the external generator require provider
  resolution;
- interpretation divergences require TEC clarification;
- missing cases, duplicate inputs, infrastructure errors and unclassified
  differences cannot pass;
- even a zero-discrepancy result does not claim TEC acceptance.

The counterpart version and acceptance reference must come from TEC or the
official accepted-product process. A local Gacrux, BBPairings, ChessPairings or
OpenPairing self-check may qualify the transport in staging, but cannot be
relabeled as the independent official cross-test.

