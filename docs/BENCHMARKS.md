# Reproducible benchmarks

Run:

```console
composer benchmark
```

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
