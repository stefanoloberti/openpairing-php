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
- unsupported TRF `250` input fails closed until its complete official exchange
  semantics are implemented.
