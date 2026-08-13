# CLI

After `composer install`, pair canonical JSON from a file or standard input:

```console
php bin/openpairing.php state.json
php bin/openpairing.php --input-format=json - < state.json
```

TRF-2026 input is supported through an explicit flag or a `.trf` suffix:

```console
php bin/openpairing.php --input-format=trf tournament.trf
php bin/openpairing.php tournament.trf
```

The public codec is intentionally fail-closed and requires record `192` to
declare the pairing system. Older Dutch TRFs that omit it must be upgraded
explicitly, for example by adding `192 FIDE_DUTCH_2025`; the CLI does not guess
the pairing system. The historical laboratory verifier has a separately
attested compatibility normalizer for legacy corpora and is not the public
import contract.

Apply the official Baku virtual-point schedule before pairing:

```console
php bin/openpairing.php --baku tournament.json
php bin/openpairing.php --baku --baku-ga-last-id=42 late-entry-round.json
```

The second form preserves the canonical player identity of GA's last member
after late entries. TRF `250` schedules are decoded directly (and legacy
`XXA` schedules are normalized to that representation). `--baku` is the
explicit alternative that calculates the official Baku schedule for JSON or
TRF state rather than replacing an imported announced schedule implicitly.

Produce final individual standings using the TRF `202` or `212` record already
present in the input:

```console
php bin/openpairing.php --standings tournament.trf
php bin/openpairing.php --mode=standings state.json
```

Or supply a TRF26 comma-separated tie-break list explicitly. This form uses
tournament points as the primary criterion, like record `202`:

```console
php bin/openpairing.php --standings --tie-breaks=DE/P,BH/C1/P,SB/C1 state.json
```

Standings output contains the canonical rank order, unresolved groups, exact
rational evaluations and one row per participant with competition rank. A
record `212` may include `PTS` at any position in its complete rank-order list.

`--input-format=auto` is the default. JSON input is capped before parsing at
1 MiB and TRF input at 2 MiB, including standard input. The pairing system in
the decoded state selects Dutch or Burstein; unsupported systems fail closed.

Standard output contains `PairingResult` JSON in pairing mode and standings
JSON in standings mode. Structured errors go to standard error. Exit codes are:

- `0`: complete result and independent validation passed;
- `1`: explicit no-solution/resource-limit or validation violation;
- `2`: infrastructure/internal failure;
- `3`: invalid, oversized or unsupported input/arguments.

The production CLI performs no network calls and invokes no upstream process.
