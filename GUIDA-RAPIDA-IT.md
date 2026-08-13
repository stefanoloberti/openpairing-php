# OpenPairingPHP: guida rapida

## Requisiti

- PHP 8.3 o successivo;
- estensione GMP consigliata per tornei medi e grandi;
- nessuna connessione Internet richiesta durante l'uso.

Lo ZIP `runtime` contiene già l'autoload Composer ottimizzato. Dopo averlo
estratto, aprire un terminale nella cartella del programma.

Verificare la versione installata con `php bin/openpairing.php --version`.

## Generare il prossimo abbinamento

Con un torneo TRF-2026 individuale:

```console
php bin/openpairing.php torneo.trf > abbinamento.json
```

Il TRF deve dichiarare il sistema di abbinamento con il record `192`, per
esempio `192 FIDE_DUTCH_2025`. Il programma non indovina questo dato nei file
legacy perché Dutch, Burstein e Round Robin hanno significati diversi.

Con il formato JSON canonico:

```console
php bin/openpairing.php stato.json > abbinamento.json
```

Un input pronto è incluso in `examples/pairing-round-1.json`.

L'output include tavoli, eventuale PAB, float, diagnostica e risultato della
validazione indipendente. Exit code `0` indica un risultato completo e valido.

## Calcolare classifica e tie-break

Usare i criteri dichiarati nei record TRF `202`/`212`:

```console
php bin/openpairing.php --standings torneo.trf > classifica.json
```

Oppure indicarli esplicitamente:

```console
php bin/openpairing.php --standings --tie-breaks=DE/P,BH/C1/P,SB/C1 torneo.trf > classifica.json
```

Si può provare subito con `examples/standings-round-2.json`.

Sono implementati tutti i 17 metodi individuali obbligatori FIDE 2026, più
`STD`, `TPN`, `RTNG` e la chiave `PTS`, con le varianti applicabili descritte in
`docs/TIE-BREAKS.md`.

## Limiti della release candidate

- prodotto individuale, non team;
- massimo 256 giocatori e 256 round nel formato pubblico;
- record TRF di accelerazione `250` importato ed esportato come schedule per
  turno; il vecchio record per-giocatore `XXA` resta rifiutato; Baku è anche
  disponibile sullo stato JSON tramite `--baku`;
- Dutch usa il matcher polinomiale; Burstein mantiene una ricerca limitata e
  può restituire `resource-limit` sui bracket difficili;
- release candidate indipendente, non certificata o approvata dalla FIDE.

Dettagli completi: `README.md`, `docs/CLI.md`, `docs/TRF-2026.md` e
`docs/TIE-BREAKS.md`.
