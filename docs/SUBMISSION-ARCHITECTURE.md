# Submission architecture decision

Decision date: 2026-08-15. This record prevents evidence from two different
products from being combined accidentally.

## Observed product boundary

OpenPairingPHP is a PHP library and three offline CLIs: pairing/standings, PTC
and RTG. It does not currently provide the complete Tournament Handler Program
(THP) workflow described by FIDE: rating-list update, tournament/player data
management, result entry and an operator-facing FIDE mode.

ChessPairings Desktop is a separate Windows THP candidate. Its current
submission ledger identifies a post-0.45.5 checkpoint and an existing
BBPairings/Gacrux validation chain; it does not identify OpenPairingPHP as its
embedded production engine. Consequently:

- the Desktop evidence cannot be used as evidence for OpenPairingPHP;
- the OpenPairing corpus cannot be presented as if it tested the Desktop
  executable; and
- replacing or adding the Desktop pairing engine would create a new product
  identity requiring a fresh exact-binary gate.

## Recommended sequence

1. Keep ChessPairings Desktop as the nearer-term THP TAPC candidate and close
   its existing replacement-RC gate without changing its engine stack.
2. Ask TEC in writing whether a standalone pairing engine/library can receive
   TAPC or another technical recognition, and which checklist applies.
3. If TEC requires a THP, choose deliberately between:
   - building a complete OpenPairing THP shell; or
   - integrating OpenPairing into a later ChessPairings product version.
4. Do not start either integration merely to reuse the current corpus. First
   freeze the product scope and define the exact acceptance matrix.

This is a sequencing decision, not a technical rejection of OpenPairing. The
public PTC/RTG, TRF26 codec and corpus are useful component evidence and make a
future integration substantially safer.

## Gate for any future integration

An OpenPairing-powered ChessPairings candidate must prove, on one exact
product identity:

- the packaged PHP runtime and OpenPairing source/engine hashes;
- player/rating import, result entry, withdrawals, byes and recovery;
- English FIDE-mode controls and non-bypass tests;
- TRF26 import/export and all declared tie-break/acceleration paths;
- PTC and RTG public-download behavior from the delivered package;
- complete identity-scoped corpus replay plus product-level end-to-end tests;
- reproducible installer/runtime archives and the controlled tester
  environment.

No previous Desktop or OpenPairing count may silently replace one of these
exact-candidate checks.

The fail-closed state, transaction, identity and evidence boundary for such a
candidate is specified in `THP-INTEGRATION-CONTRACT.md`.
