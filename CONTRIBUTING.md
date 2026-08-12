# Contributing

Keep changes deterministic, offline, strict-typed, and covered by tests. Do not
use binary floats for regulatory comparisons. A behavioural or source-level
port must identify its upstream file/function/commit, licence, modifications,
PHP target, and regression test in `docs/PORTING-MAP.md`.

Run `composer check` before submitting a change. Bug fixtures must contain the
smallest reproducible canonical state, schema version, fixed seed when relevant,
expected comparison category, and upstream commit/hash. Never add private
tournament information, credentials, downloaded executables, or upstream
working trees to Git.

