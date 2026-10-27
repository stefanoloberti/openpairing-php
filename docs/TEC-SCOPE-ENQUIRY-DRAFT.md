# Draft TEC scope enquiry

This is an owner-review draft. It has not been sent.

**Subject:** Applicable FIDE TEC assessment route for a standalone pairing engine

Dear FIDE Technical Commission,

We are preparing OpenPairingPHP, an offline deterministic implementation of
the FIDE Dutch and Burstein 2026 pairing systems, individual tie-breaks, Baku
acceleration and TRF26. The package is a PHP library and command-line tool. It
also includes a free public Pairings and Tie-Breaks Checker (PTC) and
deterministic Random Tournament Generator (RTG). It is not currently a
complete operator-facing Tournament Handler Program.

Before selecting an application category, could you please clarify:

1. Can a standalone pairing engine/library be assessed under TAPC or another
   current TEC procedure, or must the submitted product be a complete THP?
   We understand that TEC Manual 1.24 Article 3.9.4 describes an external-engine
   exemption only when that engine is already integrated into another
   FIDE-accepted THP; OpenPairingPHP does not claim that exemption.
2. If it must be part of a THP, is the engine assessed only through the exact
   THP version that embeds it?
3. May an initial application declare individual FIDE Dutch only, while
   retaining Burstein and team functionality outside the submitted FIDE mode?
4. Articles 7.2.3 and 7.2.4 describe PTC and RTG as embedded in the THP. Is a
   free CLI distributed in the exact same installer/runtime and using the same
   production engine acceptable, or must it also be launched from the THP UI?
5. Is fail-closed import of a declared individual TRF26 surface acceptable
   when unsupported/non-applicable team or NRS records are rejected explicitly,
   and what minimum TRF16/TRF06 backward-compatibility surface is required?
6. For generic individual TRF26 record `299`, may a first application reject
   the record fail-closed if the submitted product does not claim manual score
   adjustments, or is support mandatory in FIDE mode? If it is mandatory,
   when do one-round and all-round adjustments become active for subsequent
   pairing scores, and which tie-break operands must include them?
7. Which current SDPC/TAPC form and technical checklist should be used for this
   product class?
8. For the two 50,000-tournament cross-tests in TEC Manual 1.24 Article 3.9.4,
   which accepted/available RTG and PTC implementations should applicants use,
   and may the exchange be arranged before the exact THP SDPC is filed?

We will not describe the product as FIDE approved or endorsed before a formal
decision. We can provide the exact version, source/runtime hashes, English
manual, controlled test environment and reproducible validation evidence once
the applicable scope is confirmed.

Kind regards,

[OWNER LEGAL NAME]
[MANUFACTURER/VENDOR NAME]
[CONTACT DETAILS]

## Owner checklist before sending

- Confirm the legal applicant and manufacturer/vendor names and one monitored
  contact address.
- Confirm whether the intended application is the exact ChessPairings THP or a
  request for a separately assessable OpenPairingPHP component route.
- Attach no corpus, source archive or personal tournament data to the initial
  scope question unless TEC requests it through an agreed channel.
- Do not quote RC8, RC38 or any later release candidate as the submitted
  product version; the stable SDPC/TAPC identity has not been frozen.
- Preserve the sent message and written reply as external gate evidence. A
  verbal or inferred answer cannot close `tec-product-scope`.
