# Restricted Visibility per Flarum 2.x

Permette di rendere **singoli post** o **intere discussioni** visibili solo a certi **gruppi** o **utenti**.
Chi non ha il permesso di vederli **non li vede da nessuna parte** (discussione, ricerca, profilo) e **non riceve
né notifiche né email**, nemmeno se è iscritto alla discussione.

> Stato: scritta per Flarum 2.0.2 sulla base della documentazione ufficiale 2.x. Non è stata eseguita contro
> un'installazione reale. **Prima di metterla in produzione esegui tutta la checklist in `TESTING.md` su una copia
> di staging.** Nessun software può essere garantito "privo di vulnerabilità": qui sotto trovi cosa è protetto,
> come, e quali limiti restano.

## Installazione

Requisiti: Flarum 2.0 o successivo e PHP 8.3 o successivo.

```bash
composer require peopleinside/flarum-restricted-visibility
php flarum migrate
php flarum cache:clear
```

Poi abilita l'estensione da **Admin → Estensioni → Restricted Visibility** e assegna i permessi (vedi sotto).

### Aggiornamento

```bash
composer update peopleinside/flarum-restricted-visibility
php flarum migrate
php flarum cache:clear
```

### Sviluppo

I bundle JS compilati sono già in `js/dist` (vanno inclusi nel repository). Se modifichi i sorgenti:
`cd js && npm install && npm run build`.

## Permessi (Admin → Permessi)

| Permesso | Chi dovrebbe averlo | Effetto |
|---|---|---|
| Restringere la visibilità dei propri post e discussioni | utenti fidati | mostra il pulsante nel composer |
| Vedere tutti i contenuti riservati | moderatori | bypass delle restrizioni e gestione di quelle altrui |

Gli amministratori hanno sempre entrambi. **Nessun permesso viene concesso per impostazione predefinita.**

## Uso

- **Nuovo post / nuova discussione**: nel composer, pulsante con l'occhio barrato → scegli gruppi e/o utenti → pubblica.
- **Modifica**: menu del post (o della discussione) → "Modifica visibilità…", oppure dal composer di modifica.
- **Badge**: un'icona *occhio barrato* viola (diversa dal lucchetto della discussione bloccata) compare sulla discussione se
  contiene post riservati **che chi guarda può vedere**. Per gli altri il conteggio è sempre 0, quindi nulla trapela.

### Regole

- La visibilità si può solo **restringere**: la visibilità effettiva è l'intersezione tra discussione e post.
- Autore e utenti con "Vedere tutti i contenuti riservati" vedono sempre.
- Il **primo post** non si può restringere da solo: restringi la discussione.
- Chi non può vedere un post riservato non sa che esiste (invisibilità totale).
- Solo l'autore e chi ha il bypass vedono l'elenco dei destinatari; i destinatari vedono "Riservato".
- Modificare la visibilità di un post esistente richiede anche di poter modificare quel post (limiti di tempo di modifica inclusi).

## Disinstallazione completa (senza residui)

I dati **restano** se disattivi o rimuovi l'estensione. Per una pulizia completa, in quest'ordine:

1. **Admin → Estensioni → Restricted Visibility → "Zona pericolosa" → "Elimina tutti i dati dell'estensione…"**
   Scegli cosa fare dei contenuti riservati (**Nascondi** è preselezionato), digita `ELIMINA`.
   Elimina le restrizioni, i permessi e le impostazioni, in una sola transazione.
2. **Disattiva** l'estensione.
3. **"Elimina dati"** nella stessa pagina (o `php flarum migrate:reset --extension peopleinside-restricted-visibility`): rimuove le tabelle e i record di migrazione.
4. `composer remove peopleinside/flarum-restricted-visibility`.

> ⚠️ Se disattivi o rimuovi l'estensione **senza** il passo 1, i contenuti riservati diventano visibili a tutti.
> Se salti solo il passo 3, restano tabelle vuote e inoffensive.

| Modalità | Effetto sui contenuti riservati |
|---|---|
| `hide` (predefinita) | li nasconde (`hidden_at`), visibili solo ai moderatori; nulla va perso |
| `delete` | li elimina definitivamente |
| `public` | rimuove solo le restrizioni: diventano visibili a tutti |

### Comando da terminale

```bash
php flarum peopleinside-restricted-visibility:purge [--mode=hide|delete|public] [--actor=ID] [--dry-run] [--yes]
```

| Opzione | Significato |
|---|---|
| `--mode`, `-m` | cosa fare dei contenuti riservati (predefinito `hide`) |
| `--actor` | ID utente registrato come autore del nascondimento (predefinito: primo amministratore) |
| `--dry-run` | mostra solo il riepilogo |
| `--yes`, `-y` | salta la conferma (per script); senza, va digitato `ELIMINA` |

Consigliato su forum grandi: evita i timeout delle richieste web. Dopo il comando, prosegui dal passo 2.

## Come funziona la sicurezza

- **Scope di visibilità** (`Extend\ModelVisibility`) su Discussion e Post: vale per API, ricerca, conteggi e relazioni.
  Il post ripete esplicitamente anche il controllo della discussione (difesa in profondità).
- **Query parametrizzate** (query builder), ID sempre convertiti a intero, nessun input concatenato in SQL.
- **Scrittura protetta**: i campi sono scrivibili solo con permesso + (autore o bypass); gruppi e utenti sono validati
  (esistenti, visibili all'attore, con limiti di 50 e 100); in caso di errore la richiesta fallisce, non si scarta in silenzio.
- **Notifiche**: filtro centralizzato (`Notification::beforeSending`) prima di qualunque driver, quindi vale per campanella ed
  email. Controlla soggetto e tutti i post/discussioni contenuti nel blueprint (es. risposta e post menzionato). Le restrizioni
  non ancora scritte nel DB sono tenute in memoria per evitare che la notifica parta prima del salvataggio.
- **Rimozione dati**: solo amministratori, protetta da CSRF di Flarum, conferma testuale verificata anche lato server
  (`hash_equals`), transazione unica.
- **Foreign key con `ON DELETE CASCADE`**: cancellando un post, una discussione, un utente o un gruppo, le righe collegate spariscono.

## Limiti noti (da conoscere prima di andare in produzione)

1. **Dati denormalizzati della discussione**: contatore messaggi, ultimo post, data e autore dell'ultimo intervento sono calcolati
   anche sui post riservati. Un utente non autorizzato può quindi notare che la discussione "risale" o vedere il nome
   dell'ultimo autore, senza vedere il contenuto. Non è risolto: per casi sensibili usa la restrizione sull'intera discussione.
2. **Estensioni di terze parti** che leggono i post con query dirette o indici esterni (Elasticsearch, feed RSS, esportazioni, bot)
   possono aggirare gli scope. Vanno verificate caso per caso.
3. **Realtime** (Pusher / flarum/realtime): verifica che l'evento non contenga il testo; il contenuto è ricaricato via API
   (quindi filtrato), ma controllalo (vedi `TESTING.md`).
4. **Restrizione dopo la pubblicazione**: notifiche già inviate e contenuti già letti non si possono ritirare.
5. **Chi ha il permesso può restringere i post altrui?** No, solo i propri; i moderatori con il bypass possono agire su tutti,
   anche togliendo una restrizione impostata da un altro.
6. La rimozione in modalità `hide`/`delete` dal pannello web su forum molto grandi può andare in timeout: usa il comando CLI.
