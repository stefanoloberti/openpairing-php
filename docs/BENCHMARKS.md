# Reproducible benchmarks

Run:

```console
composer benchmark
```

For the latency and memory of one pairing turn, excluding fixture preparation,
run for example:

```console
composer benchmark-turn -- --players=80 --round=7 --rounds=9 --system=dutch --repetitions=3
composer benchmark-turn -- --players=150 --round=9 --rounds=11 --system=dutch --repetitions=3
```

`tools/benchmark_turn.php` first advances a deterministic synthetic tournament
to the requested round, then pairs the same immutable state repeatedly. Its
JSON separates preparation time from minimum/median/maximum turn latency,
records peak memory, backend, the complete runtime/source/engine identity and
one deterministic functional-result hash.
Timing diagnostics and trace prose are deliberately excluded from that hash.
The setup time is not production latency; the measured pairing call is. A synthetic fixture
is a reproducible capacity control, not a substitute for the historical corpus
or live shadow evidence.

`tools/benchmark.php` emits JSON containing UTC time, PHP version, OS/CPU
architecture, elapsed milliseconds, peak memory, search nodes, candidates,
backtracks and matching time where applicable. It covers initial Dutch and
Burstein rounds at 16, 32, 64, 128 and 256 players; post-seeding synthetic
rounds at 8, 12 and 16; and the independent matcher at 16, 22, 32, 64, 128 and
256 vertices.

`ExactWeightedGeneralMatcher` remains the exhaustive oracle and intentionally
reports `resource_limit` above 22 vertices. The new polynomial matcher is
covered separately at 80 vertices in the ordinary suite and at 300 vertices in
the `stress` group (`composer test-stress`). On PHP 8.3.6 in the inspected WSL
environment, a complete 300-vertex graph took about 54.1 seconds and 279 MiB.
This establishes capacity, not a latency target. Initial-round results exercise
the linear seeding path; post-seeding results exercise bracket search. Compare
only records with the same PHP/OS/configuration.

The current correctness-first mate-vector makes weights roughly 2,700 bits at
300 vertices. ADR 0007 records a post-parity experiment to derive the same
deterministic choice from visit order and remove that vector from the weight;
performance work must not precede its differential equivalence proof.

On the inspected PHP 8.4.24 Windows x64 run, 16-player post-seeding fixtures
completed in about 1.7 ms (Dutch) and 1.6 ms (Burstein), exploring respectively
197 and 104 nodes. Initial 256-player fixtures completed below 1 ms. These are
single illustrative local measurements; fresh JSON is authoritative per host.

An additional loaded-host observation on PHP 8.3.6/GMP measured a deterministic
80-player Dutch round 7 at 12.420/13.112/13.434 seconds
(minimum/median/maximum) and about 52 MiB peak memory. Ten quarantined p80
workers were running concurrently under an eight-CPU service quota, and the
benchmark process used nice level 15; this is therefore a conservative capacity
observation, not an idle production latency promise. The exact report is
`evidence/turn-benchmark-p80-r7-python69-20260815.json`.

The identity-attested Windows fallback control measured a deterministic
50-player Dutch round 7 at 5.582/5.634/5.675 seconds and 14 MiB peak memory on
PHP 8.4.24 without GMP. Its engine and `src/` hashes are exactly those of the
active RC8 campaign; the tooling commit is recorded separately. This is a
local planning observation, not a product SLA or a substitute for the shadow
gate. The exact report is
`evidence/turn-benchmark-p50-r7-windows-limbs-20260816.json`.
