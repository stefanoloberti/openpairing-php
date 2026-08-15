# Draft TEC scope enquiry

This is an owner-review draft. It has not been sent.

**Subject:** Applicable FIDE TEC assessment route for a standalone pairing engine

Dear FIDE Technical Commission,

We are preparing OpenPairingPHP, an offline deterministic implementation of
the FIDE Dutch and Burstein 2026 pairing systems, individual tie-breaks, Baku
acceleration and TRF26. The package is a PHP library and command-line tool. It
also includes a free public Pairing Test Checker and deterministic Random
Tournament Generator. It is not currently a complete operator-facing
Tournament Handler Program.

Before selecting an application category, could you please clarify:

1. Can a standalone pairing engine/library be assessed under TAPC or another
   current TEC procedure, or must the submitted product be a complete THP?
2. If it must be part of a THP, is the engine assessed only through the exact
   THP version that embeds it?
3. May an initial application declare individual FIDE Dutch only, while
   retaining Burstein and team functionality outside the submitted FIDE mode?
4. For the public PTC and RTG, are command-line tools acceptable, and may they
   use the same embedded production engine provided their purpose and limits
   are disclosed?
5. Is fail-closed import of a declared individual TRF26 surface acceptable
   when unsupported/non-applicable team or NRS records are rejected explicitly?
6. Which current SDPC/TAPC form and technical checklist should be used for this
   product class?

We will not describe the product as FIDE approved or endorsed before a formal
decision. We can provide the exact version, source/runtime hashes, English
manual, controlled test environment and reproducible validation evidence once
the applicable scope is confirmed.

Kind regards,

[OWNER LEGAL NAME]
[MANUFACTURER/VENDOR NAME]
[CONTACT DETAILS]
