import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

import PurgeModal from './components/PurgeModal';

const ID = 'peopleinside-restricted-visibility';

// In Flarum 2.x permessi, impostazioni e pagine admin si registrano con l'extender Admin
// (app.extensionData non esiste più).
export default [
  new Extend.Admin()
    .permission(
      () => ({
        icon: 'fas fa-eye-slash',
        label: app.translator.trans(ID + '.admin.permissions.restrict', {}, true),
        permission: ID + '.restrict',
      }),
      'reply'
    )
    .permission(
      () => ({
        icon: 'fas fa-user-shield',
        label: app.translator.trans(ID + '.admin.permissions.view_all', {}, true),
        permission: ID + '.viewAll',
      }),
      'moderate'
    )
    // Posizione del pulsante nel composer.
    .setting(
      () => ({
        setting: ID + '.composer_button_position',
        type: 'select',
        label: app.translator.trans(ID + '.admin.button_position.label', {}, true),
        help: app.translator.trans(ID + '.admin.button_position.help', {}, true),
        options: {
          before_submit: app.translator.trans(ID + '.admin.button_position.before_submit', {}, true),
          after_submit: app.translator.trans(ID + '.admin.button_position.after_submit', {}, true),
          after_preview: app.translator.trans(ID + '.admin.button_position.after_preview', {}, true),
        },
        default: 'after_submit',
      }),
      10
    )
    // Zona pericolosa: il callback è eseguito nel contesto di ExtensionPage.
    .setting(
      () =>
        function () {
          return (
            <div className="Form-group">
              <label>{app.translator.trans(ID + '.admin.danger.title')}</label>
              <div className="helpText">{app.translator.trans(ID + '.admin.danger.help')}</div>
              <button type="button" className="Button Button--danger" onclick={() => app.modal.show(PurgeModal)}>
                <i className="icon fas fa-trash Button-icon" />
                <span className="Button-label">{app.translator.trans(ID + '.admin.danger.button')}</span>
              </button>
            </div>
          );
        }
    ),
];
