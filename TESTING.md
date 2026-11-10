# Checklist di verifica (da eseguire su staging, con backup)

Preparazione: 4 utenti — **admin**, **mod** (permesso "Vedere tutti"), **alice** (permesso "Restringere", gruppo *Staff*),
**bob** (solo Membro) — più **carla**, con gruppo *Staff*, senza permessi speciali. Abilita le estensioni Subscriptions,
Mentions, Likes, Tags, Flags. Imposta una mail reale o un log mail.

## 1. Installazione
- [ ] **Prova con un prefisso tabelle** (es. `flma_` in `config.php`): i frammenti SQL grezzi non vengono prefissati e un errore qui dà 500 su tutta la home.
- [ ] `php flarum migrate` termina senza errori (verifica in particolare le foreign key: tipi di colonna `unsignedInteger`).
- [ ] L'estensione si abilita; il pannello admin mostra i due permessi e la "Zona pericolosa".

## 2. Visibilità (per ogni caso controlla: pagina discussione, elenco, ricerca, profilo utente, `/api/posts`, `/api/discussions`)
- [ ] Post restretto a *Staff*: visibile a alice, carla, mod, admin; **non** visibile a bob né ospite.
- [ ] Post restretto all'utente bob: visibile a bob, alice (autrice), mod; non a carla.
- [ ] Discussione restretta: nessun suo post è visibile a chi non rientra, nemmeno via `/api/posts?filter[discussion]=ID`.
- [ ] Post pubblico dentro discussione riservata: invisibile a chi non vede la discussione (intersezione).
- [ ] Prova ad aprire direttamente `/d/ID` e `/api/posts/ID` come bob: 404, non 403 con dettagli.
- [ ] Il primo post non si può restringere da solo (API: errore di scrittura).

## 3. Notifiche ed email (il punto più delicato)
- [ ] bob e carla iscritti alla discussione. alice pubblica un post riservato a *Staff*: carla riceve alert ed email, **bob nessuno dei due**.
- [ ] Verifica il test **anche con coda attiva** e con l'ordine reale degli eventi: nessuna email a bob al momento della *creazione*
      (non solo dopo una modifica). Se bob la riceve, fermati: l'ordine degli eventi in 2.0.2 è diverso da quello previsto.
- [ ] Menzione `@bob` in un post riservato a Staff: nessuna notifica a bob.
- [ ] Like a un post riservato: la notifica va solo all'autore.
- [ ] Nuova discussione riservata con tag seguito da bob: nessuna notifica a bob.
- [ ] Restringere un post già pubblicato non genera notifiche a chi ne è escluso.

### Diagnosi se una notifica arriva comunque
Sostituisci `PREFISSO_` con il prefisso delle tue tabelle (es. `flma`).
```sql
-- la restrizione è stata scritta? (deve esserci una riga per il post riservato)
SELECT * FROM PREFISSO_restricted_post_groups;  SELECT * FROM PREFISSO_restricted_post_users;
-- che tipo di notifica è arrivata e per quale soggetto?
SELECT id, type, user_id, from_user_id, subject_id, created_at FROM PREFISSO_notifications ORDER BY id DESC LIMIT 5;
```
`type = newPost` è l'iscrizione "Segui" di Flarum; i tipi di FoF Subscribed hanno un prefisso `fof-subscribed`/simile.
Se la riga di restrizione esiste ma la notifica è partita, il filtro non è stato eseguito o non ha visto il post.

## 4. Badge
- [ ] bob: nessun badge, `restrictedPostCount` = 0 nella risposta API.
- [ ] carla/alice: badge viola con occhio barrato e conteggio corretto; distinto dal lucchetto di "bloccata".
- [ ] Dopo aver pubblicato un post riservato il badge compare senza ricaricare.

## 5. Scrittura e autorizzazioni
- [ ] bob (senza permesso) invia `restrictedGroupIds` via API: errore.
- [ ] alice prova a restringere un post di carla: errore. mod: riesce.
- [ ] ID inesistenti, negativi, stringhe non numeriche, più di 50 gruppi / 100 utenti, gruppo Guest: errore di validazione.
- [ ] Utente non visibile all'attore (es. sospeso/nascosto): rifiutato.

## 6. Perdite di informazioni (verifica e decidi)
- [ ] Come bob, controlla `commentCount`, `lastPostedAt`, `lastPostedUser` della discussione dopo un post riservato (limite noto 1).
- [ ] Controlla l'evento realtime/Pusher: non deve contenere testo del post.
- [ ] Feed RSS, sitemap, ricerca esterna, export GDPR: nessun contenuto riservato.

## 7. Disinstallazione
- [ ] `php flarum peopleinside-restricted-visibility:purge --dry-run` mostra i numeri giusti.
- [ ] Pannello admin, modalità **hide**: i post riservati risultano nascosti, visibili solo a mod/admin; nulla è visibile a bob.
- [ ] Modalità **public** e **delete** su una copia del database.
- [ ] Dopo la pulizia: tabelle vuote, nessuna riga `peopleinside-restricted-visibility.*` in `settings` e `group_permission`.
- [ ] Disattiva → "Elimina dati" → `composer remove`: schema identico a prima (`mysqldump --no-data` a confronto).
- [ ] Conferma `ELIMINA` errata via API (`curl`): 422 e nessuna modifica. Utente non admin: 403.

## 8. Prestazioni
- [ ] Con Clockwork: la pagina di una discussione con 20 post riservati e l'elenco discussioni non fanno query per riga (N+1).
