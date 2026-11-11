import app from 'flarum/admin/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

const T = 'peopleinside-restricted-visibility.admin.purge.';
const CONFIRM_WORD = 'DELETE';
const BASE = () => app.forum.attribute('apiUrl') + '/restricted-visibility';

export default class PurgeModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.preview = null;
    this.mode = 'hide'; // scelta predefinita e più sicura
    this.confirmation = '';
    this.running = false;
    this.report = null;
    this.error = null;

    app.request({ method: 'GET', url: BASE() + '/purge-preview' }).then((r) => {
      this.preview = r.data;
      m.redraw();
    });
  }

  className() {
    return 'PeopleInsidePurgeModal Modal--medium';
  }

  title() {
    return app.translator.trans(T + 'title');
  }

  content() {
    if (this.report) return this.doneView();
    if (!this.preview) return <div className="Modal-body"><LoadingIndicator /></div>;

    const p = this.preview;
    const modes = ['hide', 'delete', 'public'];

    return (
      <div className="Modal-body">
        <p>{app.translator.trans(T + 'summary', { discussions: p.discussions, posts: p.posts, groups: p.groupLinks, users: p.userLinks })}</p>

        <div className="Form-group">
          <label>{app.translator.trans(T + 'choose')}</label>
          {modes.map((mode) => (
            <label className="checkbox">
              <input type="radio" name="peopleinside-purge-mode" checked={this.mode === mode} onchange={() => (this.mode = mode)} />
              <strong>{app.translator.trans(T + 'mode_' + mode)}</strong>
              <div className="helpText">{app.translator.trans(T + 'mode_' + mode + '_help')}</div>
            </label>
          ))}
        </div>

        <div className="Alert Alert--error" style="margin:12px 0">{app.translator.trans(T + 'warning')}</div>

        <div className="Form-group">
          <label>{app.translator.trans(T + 'type_to_confirm', { word: CONFIRM_WORD })}</label>
          <input className="FormControl" value={this.confirmation} oninput={(e) => (this.confirmation = e.target.value)} autocomplete="off" />
        </div>

        {this.error && (
          <div className="Alert Alert--error" style="margin:12px 0">
            <p>{this.error}</p>
            <p>{app.translator.trans(T + 'error_retry')}</p>
          </div>
        )}

        <Button
          className="Button Button--danger"
          loading={this.running}
          disabled={this.confirmation !== CONFIRM_WORD}
          onclick={() => this.run()}
        >
          {app.translator.trans(T + 'run')}
        </Button>
      </div>
    );
  }

  doneView() {
    const r = this.report;
    return (
      <div className="Modal-body">
        <p>
          {r.status === 'queued'
            ? app.translator.trans(T + 'queued')
            : app.translator.trans(T + 'done', { discussions: r.affectedDiscussions, posts: r.affectedPosts })}
        </p>
        <p><strong>{app.translator.trans(T + 'next_steps')}</strong></p>
        <Button className="Button" onclick={() => this.hide()}>{app.translator.trans(T + 'close')}</Button>
      </div>
    );
  }

  run() {
    this.running = true;
    this.error = null;
    app
      .request({ method: 'POST', url: BASE() + '/purge', body: { mode: this.mode, confirmation: this.confirmation } })
      .then((r) => {
        this.report = r.data;
      })
      .catch((e) => {
        this.error = e?.response?.errors?.[0]?.detail || app.translator.trans('core.lib.error.generic_message');
      })
      .then(() => {
        this.running = false;
        m.redraw();
      });
  }
}
