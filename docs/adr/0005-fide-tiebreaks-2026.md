# ADR 0005: FIDE tie-break calculations

Status: accepted, 2026-08-11.

Tie-breaks are domain services, not pairing-engine side effects. All arithmetic
uses exact `Score` values. Scalar methods and ordered ranking are separate
because Direct Encounter is group-dependent and recursive.

The source of truth is the official FIDE regulation effective 1 March 2026.
The final-standing BH/SB Article 16 treatment is intentionally separate from
the Burstein in-round opposition evaluation, whose official common rules are
different. Unsupported modifiers and unrated ARO states fail closed rather
than applying guessed defaults.
