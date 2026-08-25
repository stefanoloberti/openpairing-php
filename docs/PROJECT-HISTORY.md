# OpenPairingPHP: cronaca del progetto

> Stato: indice da revisionare. È l’artefatto leggero che accompagna il paper
> tecnico, non una sua traduzione o versione abbreviata.
>
> Lingua della prima bozza completa: italiano. Dopo la revisione tecnica potrà
> essere preparata anche un’edizione inglese per il sito pubblico.

## Patto editoriale

La cronaca racconterà il progetto in ordine cronologico a chi non leggerà mai
un paper. Il tono resterà concreto, onesto e privo di marketing da lancio. Gli
incidenti non saranno nascosti e i numeri della campagna entreranno soltanto
quando potranno essere collegati a un JSON di evidenza promosso e al suo
SHA-256.

## Titoli proposti

1. **OpenPairingPHP: cronaca di un motore verificato prima di essere creduto**
2. **Prima l’evidenza: storia del porting oracle-first di OpenPairingPHP**
3. **Dalle regole agli oracoli: come è nato OpenPairingPHP**

## Indice

### 1. Perché iniziare

L’apertura spiegherà il vuoto pratico: un’applicazione PHP aveva bisogno di un
motore di abbinamento offline e ispezionabile, mentre gli strumenti di
riferimento disponibili vivevano in altri linguaggi e dovevano rimanere
oracoli di sviluppo, non dipendenze runtime. La scelta non fu “tradurre finché
i test passano”, ma costruire un metodo capace di respingere anche le proprie
spiegazioni più convincenti.

Fonti: `README.md`, `docs/PORTING-MAP.md`, `docs/UPSTREAM.md`.

### 2. Il primo motore funzionante non era ancora evidenza

Il capitolo introdurrà l’implementazione limitata, il primo campione congelato
e la differenza tra una tappa d’implementazione e un gate chiuso. I contatori
obsoleti anteriori all’epoca semantica non saranno riutilizzati come risultati.

Fonti: `docs/HISTORICAL-PAIRING-VERIFICATION.md`,
`docs/ORACLE-FIRST-METHOD.md`.

### 3. Tre modi indipendenti per dire “questo risultato è sbagliato”

Il solver limitato, BBP e Gacrux saranno presentati in linguaggio accessibile.
Il punto sarà spiegare perché l’accordo conta soltanto quando gli strumenti
possono fallire in modo diverso e perché il nome della prima differenza è un
indizio, non una diagnosi.

Fonti: `docs/ORACLE-FIRST-METHOD.md`, `NOTICE`.

### 4. Le spiegazioni attraenti che non hanno retto

È il corrispettivo narrativo della sezione centrale del paper. Racconterà otto
episodi brevi: le presunte varianti equivalenti, l’autoload stantio, C9 come
causa unica, la semantica del float del PAB, la teoria forfeit-colore,
l’apparente regressione sul secondo host e la fase exchange che esisteva davvero
ma non causava il difetto p50 osservato. L’ottavo episodio sarà operativo: il
presunto rallentamento p80, smentito ricostruendo la baseline dai tempi interni
dei report conservati invece che dalla memoria e dalla cadenza delle promozioni
atomiche. Ogni storia terminerà con la regola operativa che ne è nata.

Fonti e mappa delle evidenze: Sezione 4 di
`docs/paper/ORACLE-FIRST-PAPER.md`.

Evidenza dell’episodio operativo:

- `docs/evidence/p80-throughput-diagnostic-20260816.json`
  - SHA-256: `7c7d58f19cae9717602bc24ede5cd33f1f12d403ed9b61256a354c023971663f`

### 5. Quando era sbagliata la baseline

Il reset dell’epoca semantica mostrerà perché un lavoro onesto a volte deve
scartare progressi apparentemente buoni. Correggere la storia ricostruita non
migliora retroattivamente il motore: cambia la domanda che gli stavamo facendo.

Evidenze di riferimento:

- `docs/evidence/pairing-epoch2-dutch-500.json`
  - SHA-256: `dbdd9b5fa08c5eb82b7a7af3d0e16dab8f80b15ca2a9b84cb2c75bb00254a6bd`
- `docs/evidence/pairing-epoch2-v3-pab-unmatched-490.json`
  - SHA-256: `c77b71674da4249503988e4a48496197368a5120f98be09c1037c6d314d39e87`

### Interludio — Aritmetica esatta senza scorciatoie invisibili

Una breve parentesi spiegherà perché l’aritmetica è parte del metodo e non un
dettaglio d’implementazione: backend puro PHP a limb da 30 bit e backend GMP,
identità runtime esplicita, stress sequenziale di parità e cache del residuo
riusata soltanto con una prova di ottimalità ancora valida. Il controllo
negativo sul mate-vector sarà raccontato senza citarne percentuali o larghezze
finché non verrà localizzato il relativo JSON A/B promosso.

Evidenze di riferimento:

- `docs/evidence/cross-worker-python69-bootstrap-20260815.json`
  - SHA-256: `835e3e30bd5df4913bb75f0016592b53ee53d3ba17d44960bcc993bb7fb3ea11`
- `docs/evidence/performance-residual-cache-p80-round1.json`
  - SHA-256: `bc2f6903e84a4d047da0d95dcf43a4df94758e851108b9f9124108118a65ecf9`
- `[PENDING: JSON A/B promosso del mate-vector e SHA-256]`

### 6. Le notti delle campagne immutabili

Il capitolo descriverà shard, coppie report/classificazione, file STOP, lock
d’identità, riconciliazione del denominatore e monitoraggio solo su evento. Le
macchine saranno personaggi soltanto quando servono a mostrare il metodo
operativo: il capitolo non diventerà un diario di contatori orari.

Fonti: `docs/CORPUS-GATE.md`, `docs/CORPUS-SHARD-OBSERVABILITY.md`,
`docs/TOOLCHAIN.md`.

### 7. L’infrastruttura ha fallito; il verdetto no

Il runner sbagliato, il checkout stantio, il guasto del canale cross-host, i
riavvii del servizio e le coppie di evidenza incomplete saranno raccontati come
incidenti. Non è teatro della resilienza: il lettore deve vedere con precisione
dove i controlli fail-closed hanno impedito che un evento infrastrutturale
diventasse un risultato del motore.

Evidenze di riferimento:

- `docs/evidence/cross-worker-final-channel-negative-20260817.json`
  - SHA-256: `5ec30df6ce28be23824cf2e000a04b7c14ff30cd318f2bc95eb3de9d2cb35378`
- `docs/evidence/cross-worker-first-accepted-import-20260817.json`
  - SHA-256: `b0c51685fa80e43decc028f5f820e48a5d24184cf040b9208d08aaa507a93c86`
- `[PENDING: ulteriori record d’incidente promossi]`

### 8. Due host, un risultato semantico

Gli shard cross-host in quarantena possono diventare un esperimento di
replicazione quando l’host primario avrà calcolato indipendentemente gli stessi
input. Il confronto dovrà essere semantico, torneo per torneo e turno per
turno, non una semplice uguaglianza di byte. Finché non esisteranno lo script e
il risultato promosso, il capitolo rimarrà una proposta esplicita, non un claim.

Evidenza: `[PENDING: JSON del confronto semantico cross-host e SHA-256]`.

### 9. Cosa prova, e cosa non prova, la campagna storica

Le partizioni finali chiuse saranno riassunte senza mescolare scansioni del
parser, piloti di capacità, replay storico, replay accelerato o shadow di
prodotto. Una sola tabella generata sarà condivisa con il paper; il testo
spiegherà il suo significato in linguaggio non specialistico.

Evidenza: `[PENDING: aggregati finali promossi della campagna]`.

### 10. Il laboratorio umano-AI

La cronaca descriverà come una direzione umana e agenti AI hanno lavorato
insieme: confini e autorizzazioni, revisione indipendente, controesempi ridotti,
sessioni ordinarie di diagnosi, sessioni immutabili di monitoraggio e rifiuto di
trattare una spiegazione fluente come evidenza. La revisione del secondo modello
sarà visibile quando ha proposto un’ipotesi falsificabile, sia corretta sia
smentita.

Fonti: `docs/ORACLE-FIRST-METHOD.md`,
`docs/evidence/autonomy-window-handoff-20260816.json`,
`docs/evidence/autonomy-closeout-1700-20260816.json`.

### 11. Da un motore verificato a un prodotto utilizzabile

Il finale passerà dalla campagna autonoma delle macchine al fronte umano:
inventario API, freeze, SPEC shadow, benchmark per turno e gate completo di
prodotto. Dirà chiaramente che un motore verificato, da solo, non costituisce un
prodotto rilasciato, certificato o operativamente sicuro.

Questi documenti sono già vivi, non segnaposto: l’inventario del percorso di
abbinamento è congelato e l’adapter, il ledger dei tentativi, il coordinatore
sintetico e il verificatore shadow hanno checkpoint isolati PASS. La cronaca
dirà con altrettanta chiarezza cosa manca: nessun call site live ha avviato
evidenza shadow ufficiale, il confine transazionale e di rollback è ancora
aperto e il gate prodotto resta `NO_GO`.

Fonti: `docs/CHESSPAIRINGS-INTEGRATION-INVENTORY.md`,
`docs/SHADOW-VALIDATION.md`, `docs/FIDE-SUBMISSION-READINESS.md`.

Checkpoint di riferimento:

- `docs/evidence/chesspairings-pairing-path-inventory-20260816.json`
  - SHA-256: `3bd441ffdfb74b9544fbd673c64aef5edf0fcd58558e12315ff86cc64c5f36db`
- `docs/evidence/chesspairings-shadow-adapter-bootstrap-20260816.json`
  - SHA-256: `bab73a5913d0589b1c456aa220168aafd84975fe221798ebdd3e31342ccc80a2`
- `docs/evidence/chesspairings-shadow-observation-coordinator-checkpoint-20260816.json`
  - SHA-256: `a8f4d7a070c7a2e3d78e76e1ee0736cb2ab447bbce12e64d20c0002cf4aba8b7`
- `docs/evidence/shadow-evidence-verifier-semantics-checkpoint-20260816.json`
  - SHA-256: `06ec515a2065c749c155d7438ee97e813322374c486d685e81d9996e0f7112bf`

### 12. Cosa resta aperto

L’ultimo capitolo elencherà confini prestazionali irrisolti, debito algoritmico
mappato ma aperto, corsie della campagna e lavoro di prodotto. Sarà aggiornato
dalle stesse fonti autorevoli del paper, mai a memoria.

## Piano di pubblicazione

1. Revisionare questo indice insieme allo scheletro del paper.
2. Mantenere la prima versione sul sito come bozza WordPress non pubblicata,
   senza menu, collegamenti interni o esposizione nella sitemap.
3. Espandere il testo soltanto quando inventario API e SPEC shadow sono
   allineati alle priorità correnti.
4. Pubblicare la cronaca dopo la revisione tecnica e quando ogni affermazione
   quantitativa avrà un percorso JSON e uno SHA-256.
5. Aggiungere collegamenti interni e metadati SEO soltanto quando la versione
   pubblica sarà finale: la reperibilità non deve precedere la chiusura dei
   fatti.
