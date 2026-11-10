import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Model from 'flarum/common/Model';
import Discussion from 'flarum/common/models/Discussion';
import Post from 'flarum/common/models/Post';
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
  // ---- modelli
  for (const Klass of [Discussion, Post]) {
    Klass.prototype.isRestricted = Model.attribute('isRestricted');
    Klass.prototype.canRestrictVisibility = Model.attribute('canRestrictVisibility');
    Klass.prototype.restrictedGroupIds = Model.attribute('restrictedGroupIds');
    Klass.prototype.restrictedUsers = Model.attribute('restrictedUsers');
  }
  Discussion.prototype.restrictedPostCount = Model.attribute('restrictedPostCount');

  const canUse = () => !!app.forum.attribute('peopleinsideCanRestrictVisibility');

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

  // ---- etichetta sui post riservati (CommentPost è un modulo differito)
  extend('flarum/forum/components/CommentPost', 'headerItems', function (items) {
    const post = this.attrs.post;
    if (!post.isRestricted?.()) return;

    const names = [];
    (post.restrictedGroupIds?.() || []).forEach((id) => {
      const g = app.store.getById('groups', String(id));
      if (g) names.push(g.namePlural());
    });
    (post.restrictedUsers?.() || []).forEach((u) => names.push('@' + u.name));

    const label = names.length
      ? app.translator.trans(ID + '.forum.post.restricted_to', { names: names.join(', ') })
      : app.translator.trans(ID + '.forum.post.restricted');

    items.add(
      'restricted',
      <span className="Post-restricted" title={label}>
        <i className="fas fa-eye-slash" /> {label}
      </span>,
      -10
    );
  });

  // ---- composer: pulsante + stato in composer.fields
  extend('flarum/common/components/TextEditor', 'controlItems', function (items) {
    const composer = this.attrs.composer;
    // Solo l'editor del composer principale (nuova discussione, risposta, modifica).
    if (!canUse() || !composer || composer !== app.composer) return;

    const f = composer.fields;
    const active = (f.restrictedGroupIds || []).length + (f.restrictedUserIds || []).length > 0;

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
            onsave: (groupIds, users) => {
              f.restrictedGroupIds = groupIds;
              f.restrictedUsers = users;
              f.restrictedUserIds = users.map((u) => u.id);
              m.redraw();
            },
          })
        }
      />,
      5
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
    f.restrictedGroupIds = (post.restrictedGroupIds?.() || []).map(String);
    f.restrictedUsers = post.restrictedUsers?.() || [];
    f.restrictedUserIds = f.restrictedUsers.map((u) => u.id);
  });

  // ---- menu del post / della discussione (utility differite: import asincrono)
  const openFor = (model) =>
    app.modal.show(VisibilityModal, {
      groupIds: model.restrictedGroupIds?.() || [],
      users: model.restrictedUsers?.() || [],
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
