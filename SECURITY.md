# Security policy

The production library and CLI are offline and perform no telemetry, network
or subprocess calls. Canonical JSON is capped at 1 MiB and depth 32; TRF is
capped at 2 MiB and 4096 bytes per line. Both formats limit tournaments to 256
players and 256 rounds and reject unknown schema fields/records.

Pairing searches use explicit node/candidate bounds and return a resource-limit
diagnostic without partial output. The exact matcher bounds vertices, weight
dimensions and weight magnitude. CLI input is length-limited while streaming,
before a parser receives it.

Development oracles are outside the runtime namespace. They use local pinned
upstreams, random private temporary directories, validated fixed executable
configuration, escaped paths, captured output, explicit timeouts and status
separation. They never turn an infrastructure error into a rules verdict.

Do not submit secrets or private tournament data. Until a public security
contact exists, retain a local reproducer and avoid public disclosure of
exploitable details. This pre-release remains unsuitable as the sole authority
for a live event.
