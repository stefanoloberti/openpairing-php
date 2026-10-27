# OpenPairingPHP documentation

OpenPairingPHP is an offline PHP 8.3+ engine for individual FIDE Dutch and
Burstein Swiss pairings, standings and tie-breaks. It is a library and CLI,
not a complete tournament manager, and it is not FIDE endorsed.

## Start here

- [English user manual](USER-MANUAL.md) — installation, pairing a round,
  standings, PTC/RTG and incident handling.
- [CLI reference](CLI.md) — commands, options, formats and exit codes.
- [Packaging and releases](PACKAGING.md) — archives, checksums and release
  reproduction.
- [Public API stability](API-STABILITY.md) — supported PHP integration
  surface for applications and services.
- [TRF26 support](TRF-2026.md) and [canonical JSON](CANONICAL-JSON.md) — input
  and output contracts.

## Pairing systems and tournament rules

- [Dutch and Burstein corpus coverage](CORPUS.md)
- [Baku acceleration](ACCELERATION.md)
- [Tie-breaks and standings](TIE-BREAKS.md)
- [Pairings and Tie-Breaks Checker](PTC.md)
- [Random Tournament Generator](RTG.md)

## Verification and reproducibility

- [Oracle-first method](ORACLE-FIRST-METHOD.md)
- [Historical pairing verification](HISTORICAL-PAIRING-VERIFICATION.md)
- [Corpus acceptance gate](CORPUS-GATE.md)
- [Controlled environment](CONTROLLED-ENVIRONMENT.md)
- [Benchmarks](BENCHMARKS.md)

## Product and FIDE submission boundary

- [Release status](RELEASE.md)
- [FIDE submission readiness](FIDE-SUBMISSION-READINESS.md)
- [Submission architecture](SUBMISSION-ARCHITECTURE.md)
- [Shadow validation](SHADOW-VALIDATION.md)

Campaign `PASS` results are scoped technical evidence. They do not mean that
OpenPairing or ChessPairings is certified, endorsed or approved by FIDE.

## Contributing

Reproducible bug reports are welcome. Include the smallest redistributable
TRF or canonical JSON fixture, the exact OpenPairing version, command, exit
code and expected result. Do not submit player personal data, private
tournament files, credentials, internal hostnames or administrative URLs.

