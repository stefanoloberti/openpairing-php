# ADR 0006: Baku acceleration and pairing score

## Decision

Keep standings score and virtual points as separate exact `Score` values.
Expose their sum through `Player::pairingScore()` and use it only for pairing
decisions identified by FIDE C.04.7 Article 1.5. Provide a stateless Baku
transformer rather than implicitly guessing acceleration from tournament size.

## Consequences

- standings and tie-break values cannot accidentally include virtual points;
- callers may store the current announced acceleration losslessly in canonical
  JSON or calculate it with the bundled Baku method;
- late-entry GA identity is explicit;
- TRF `250` schedules round-trip losslessly; legacy per-player `XXA` schedules
  are accepted on import and normalized to canonical `250` output. Conflicting,
  out-of-range or unknown-player acceleration records fail closed.
