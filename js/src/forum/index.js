import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Discussion from 'flarum/common/models/Discussion';
import Badge from 'flarum/common/components/Badge';
import Button from 'flarum/common/components/Button';

import VisibilityModal from './components/VisibilityModal';

const ID = 'peopleinside-restricted-visibility';

/*
 * Flarum 2.x carica "a richiesta" molti moduli (composer, editor, post, utility dei menu).
 * Non si possono importare e usare subito con `X.prototype`: per questi moduli si passa il
 * percorso come stringa a `extend` (il callback parte quando il modulo viene caricato) oppure
 * si usa `import()` asincrono. Solo i moduli sempre presenti (modelli, Badge, Button) restano
 * importati normalmente.
 */
app.initializers.add(ID, () => {
  // Gli attributi dei modelli sono registrati in ./extend.js (Extend.Model).

  const canCreatePrivate = () => !!app.forum.attribute('peopleinsideCanCreatePrivate');
  const canMakePrivate = () => !!app.forum.attribute('peopleinsideCanMakePrivate');
  const canMakePublic = () => !!app.forum.attribute('peopleinsideCanMakePublic');

  // ---- badge sulle discussioni (elenco e testata). Icona diversa dal lucchetto della discussione bloccata.
  extend(Discussion.prototype, 'badges', function (badges) {
    const whole = this.isRestricted?.();
    const count = this.restrictedPostCount?.() || 0;
    if (!whole && !count) return;

    badges.add(
      'restricted',
      <Badge
        type="restricted"
        icon="fas fa-eye-slash"
        label={app.translator.trans(ID + (whole ? '.forum.badge.discussion' : '.forum.badge.posts'), { count })}
      />,
      5
    );
  });

  // ---- etichette sui post (CommentPost è un modulo differito)
  /*
   * Il traduttore di Flarum 2.x può restituire un array di frammenti invece di una stringa.
   * Messo in un attributo (title) un array viene convertito con le virgole ("Riservato a: ,@MaBo"),
   * quindi per i tooltip si ricava sempre testo semplice.
   */
  const plain = (x) => {
    if (x == null || typeof x === 'boolean') return '';
    if (Array.isArray(x)) return x.map(plain).join('');
    if (typeof x === 'object') return x.tag === '#' ? String(x.children) : plain(x.children ?? x.text);
    return String(x);
  };

  // Nomi dei destinatari: gruppi + utenti. La virgola compare solo tra un nome e il successivo.
  const recipientNames = (model) => {
    const names = [];
    (model.restrictedGroupIds?.() || []).forEach((id) => {
      const g = app.store.getById('groups', String(id));
      if (g) names.push(g.namePlural());
    });
    (model.restrictedUsers?.() || []).forEach((u) => names.push('@' + u.name));
    return names;
  };

  const restrictedLabel = (names, keyTo, keyGeneric) =>
    plain(names.length ? app.translator.trans(ID + keyTo, { names: names.join(', ') }) : app.translator.trans(ID + keyGeneric));

  extend('flarum/forum/components/CommentPost', 'headerItems', function (items) {
    const post = this.attrs.post;
    const discussion = post.discussion?.();
    const discussionRestricted = !!discussion?.isRestricted?.();
    const postRestricted = !!post.isRestricted?.();

    // 1) Restrizione propria del post (viola).
    if (postRestricted) {
      const label = restrictedLabel(recipientNames(post), '.forum.post.restricted_to', '.forum.post.restricted');
      items.add(
        'restricted',
        <span className="Post-restricted" title={label}>
          <i className="fas fa-eye-slash" /> {label}
        </span>,
        -10
      );
    }

    if (!discussionRestricted) return;

    // 2) Primo messaggio: mostra a chi è riservata l'intera discussione (viola).
    if (Number(post.number?.()) === 1) {
      const label = restrictedLabel(recipientNames(discussion), '.forum.post.discussion_restricted_to', '.forum.post.discussion_restricted');
      items.add(
        'restricted-discussion',
        <span className="Post-restricted Post-restricted--discussion" title={label}>
          <i className="fas fa-eye-slash" /> {label}
        </span>,
        -11
      );
      return;
    }

    // 3) Messaggio senza restrizione propria in una discussione riservata: icona discreta, diversa
    //    dall'etichetta viola. Dice che il messaggio è "pubblico" ma limitato dalla discussione;
    //    se la discussione tornasse pubblica, questo messaggio sarebbe visibile a tutti.
    if (!postRestricted) {
      const title = plain(app.translator.trans(ID + '.forum.post.limited_by_discussion'));
      items.add(
        'restricted-by-discussion',
        <span className="Post-restrictedByDiscussion" data-tip={title} aria-label={title} tabindex="0" role="img">
          <i className="far fa-eye-slash" />
        </span>,
        -11
      );
    }
  });

  // ---- composer: pulsante + stato in composer.fields
  extend('flarum/common/components/TextEditor', 'controlItems', function (items) {
    const composer = this.attrs.composer;
    // Solo l'editor del composer principale (nuova discussione, risposta, modifica).
    if (!composer || composer !== app.composer) return;
    // Contenuto nuovo: serve "creare nuovi contenuti privati". In modifica: "rendere privato" (esistente) o "rendere pubblico".
    const editing = !!composer.body?.attrs?.post;
    const mayPrivate = editing ? canMakePrivate() : canCreatePrivate();
    const mayPublic = editing && canMakePublic();
    if (!(mayPrivate || mayPublic)) return;

    const f = composer.fields;
    const active = (f.restrictedGroupIds || []).length + (f.restrictedUserIds || []).length > 0;

    // Posizione scelta dall'amministratore. Nel core "submit" e "preview" hanno entrambi priorità 0,
    // quindi si fissano esplicitamente (la priorità più alta viene prima):
    //   before_submit : [Nostro] [Pubblica] [Anteprima]
    //   after_submit  : [Pubblica] [Nostro] [Anteprima]   (predefinito)
    //   after_preview : [Pubblica] [Anteprima] [Nostro]
    const position = app.forum.attribute('peopleinsideComposerButtonPosition');
    const priorities = {
      before_submit: { submit: 50, preview: 0, ours: 100 },
      after_submit: { submit: 100, preview: 0, ours: 50 },
      after_preview: { submit: 100, preview: 50, ours: 0 },
    };
    const prio = priorities[position] || priorities.after_submit;
    if (items.has('submit')) items.setPriority('submit', prio.submit);
    if (items.has('preview')) items.setPriority('preview', prio.preview);

    items.add(
      'restrict-visibility',
      <Button
        icon="fas fa-eye-slash"
        className={'Button Button--icon' + (active ? ' Button--restricted' : '')}
        aria-label={app.translator.trans(ID + '.forum.composer.button')}
        title={app.translator.trans(ID + '.forum.composer.button')}
        onclick={() =>
          app.modal.show(VisibilityModal, {
            groupIds: f.restrictedGroupIds || [],
            users: f.restrictedUsers || [],
            restricted: active || !!composer.body?.attrs?.post?.isRestricted?.(),
            canMakePrivate: mayPrivate,
            canMakePublic: mayPublic,
            onsave: (groupIds, users) => {
              f.restrictedGroupIds = groupIds;
              f.restrictedUsers = users;
              f.restrictedUserIds = users.map((u) => u.id);
              m.redraw();
            },
          })
        }
      />,
      prio.ours
    );
  });

  const addData = function (data) {
    const f = app.composer.fields;
    // Si invia solo se esiste una scelta: chi non può restringere non invia nulla.
    if (f.restrictedGroupIds !== undefined || f.restrictedUserIds !== undefined) {
      data.restrictedGroupIds = f.restrictedGroupIds || [];
      data.restrictedUserIds = f.restrictedUserIds || [];
    }
  };
  extend('flarum/forum/components/DiscussionComposer', 'data', addData);
  extend('flarum/forum/components/ReplyComposer', 'data', addData);
  extend('flarum/forum/components/EditPostComposer', 'data', addData);

  // In modifica, precompila con i valori attuali (solo se l'utente può gestirli).
  extend('flarum/forum/components/EditPostComposer', 'oninit', function () {
    const post = this.attrs.post;
    if (!post.canRestrictVisibility?.()) return;
    const f = this.attrs.composer.fields;
    // Riservato ma senza più destinatari (cancellati): non si invia nulla, altrimenti salvare il testo
    // equivarrebbe a rendere pubblico il post. Cambia solo se l'utente apre "Chi può vederlo?" e conferma.
    if (post.isRestricted?.() && (post.restrictedGroupIds?.() || []).length + (post.restrictedUsers?.() || []).length === 0) return;
    f.restrictedGroupIds = (post.restrictedGroupIds?.() || []).map(String);
    f.restrictedUsers = post.restrictedUsers?.() || [];
    f.restrictedUserIds = f.restrictedUsers.map((u) => u.id);
  });

  // ---- menu del post / della discussione (utility differite: import asincrono)
  const openFor = (model) =>
    app.modal.show(VisibilityModal, {
      groupIds: model.restrictedGroupIds?.() || [],
      users: model.restrictedUsers?.() || [],
      restricted: !!model.isRestricted?.(),
      canMakePrivate: canMakePrivate(),
      canMakePublic: canMakePublic(),
      onsave: (groupIds, users) =>
        model
          .save({ restrictedGroupIds: groupIds, restrictedUserIds: users.map((u) => u.id) })
          .then(() => m.redraw())
          .catch(() => {}),
    });

  import('flarum/forum/utils/PostControls').then(({ default: PostControls }) => {
    extend(PostControls, 'moderationControls', function (items, post) {
      if (!post.canRestrictVisibility?.()) return;
      items.add(
        'restrict-visibility',
        <Button icon="fas fa-eye-slash" onclick={() => openFor(post)}>
          {app.translator.trans(ID + '.forum.controls.restrict')}
        </Button>
      );
    });
  });

  import('flarum/forum/utils/DiscussionControls').then(({ default: DiscussionControls }) => {
    extend(DiscussionControls, 'moderationControls', function (items, discussion) {
      if (!discussion.canRestrictVisibility?.()) return;
      items.add(
        'restrict-visibility',
        <Button icon="fas fa-eye-slash" onclick={() => openFor(discussion)}>
          {app.translator.trans(ID + '.forum.controls.restrict')}
        </Button>
      );
    });
  });
});

// Obbligatorio in Flarum 2.x: senza questa riga gli extender (attributi dei modelli) non vengono eseguiti.
export { default as extend } from './extend';
