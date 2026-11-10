import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';

import miniAvatar from '../helpers/miniAvatar';

const T = 'peopleinside-restricted-visibility.forum.modal.';
const GUEST_GROUP_ID = '2';

/**
 * attrs: { groupIds: string[]|number[], users: {id, name, avatarUrl}[], onsave(groupIds: string[], users: {id,name,avatarUrl}[]) }
 */
export default class VisibilityModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);

    this.groupIds = new Set((this.attrs.groupIds || []).map(String));
    this.users = [...(this.attrs.users || [])];
    this.mode = this.groupIds.size + this.users.length > 0 ? 'some' : 'all';
    this.query = '';
    this.results = [];
    this.searching = false;
    this.error = null;
    this.timer = null;
  }

  className() {
    return 'PeopleInsideVisibilityModal Modal--small';
  }

  title() {
    return app.translator.trans(T + 'title');
  }

  content() {
    const groups = app.store.all('groups').filter((g) => g.id() !== GUEST_GROUP_ID);

    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label className="checkbox">
            <input type="radio" name="peopleinside-mode" checked={this.mode === 'all'} onchange={() => (this.mode = 'all')} />
            {app.translator.trans(T + 'everyone')}
          </label>
          <label className="checkbox">
            <input type="radio" name="peopleinside-mode" checked={this.mode === 'some'} onchange={() => (this.mode = 'some')} />
            {app.translator.trans(T + 'only_some')}
          </label>
        </div>

        {this.mode === 'some' && (
          <div>
            <div className="Form-group">
              <label>{app.translator.trans(T + 'groups')}</label>
              {groups.map((group) => (
                <label className="checkbox PeopleInsideVisibilityModal-group">
                  <input
                    type="checkbox"
                    checked={this.groupIds.has(group.id())}
                    onchange={(e) => (e.target.checked ? this.groupIds.add(group.id()) : this.groupIds.delete(group.id()))}
                  />
                  {group.namePlural()}
                </label>
              ))}
            </div>

            <div className="Form-group">
              <label>{app.translator.trans(T + 'users')}</label>
              <div className="PeopleInsideVisibilityModal-chips">
                {this.users.map((u) => (
                  <span className="PeopleInsideVisibilityModal-chip">
                    {miniAvatar(u)}
                    <span className="PeopleInsideVisibilityModal-chipName">{u.name}</span>
                    <button type="button" className="Button Button--icon Button--link" onclick={() => this.removeUser(u.id)} aria-label="remove">
                      <i className="fas fa-times" />
                    </button>
                  </span>
                ))}
              </div>
              <input
                className="FormControl"
                type="search"
                placeholder={app.translator.trans(T + 'search_placeholder')}
                value={this.query}
                oninput={(e) => this.onSearch(e.target.value)}
              />
              {this.results.length > 0 && (
                <ul className="Dropdown-menu PeopleInsideVisibilityModal-results" style="display:block;position:static;margin-top:4px">
                  {this.results.map((u) => (
                    <li>
                      <button type="button" className="Button Button--link PeopleInsideVisibilityModal-result" onclick={() => this.addUser(u)}>
                        {miniAvatar(u)}
                        <span className="PeopleInsideVisibilityModal-resultName">{u.displayName()}</span>
                        {u.username() !== u.displayName() && <span className="PeopleInsideVisibilityModal-resultUsername">@{u.username()}</span>}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        )}

        <p className="helpText">{app.translator.trans(T + 'help')}</p>
        {this.error && <p className="PeopleInsideVisibilityModal-error">{this.error}</p>}

        <div className="Form-group">
          <Button className="Button Button--primary" onclick={() => this.submit()}>
            {app.translator.trans(T + 'save')}
          </Button>
        </div>
      </div>
    );
  }

  onSearch(value) {
    this.query = value;
    clearTimeout(this.timer);
    if (value.trim().length < 2) {
      this.results = [];
      return;
    }
    this.timer = setTimeout(() => {
      app.store
        .find('users', { filter: { q: value.trim() }, page: { limit: 6 } })
        .then((users) => {
          const chosen = new Set(this.users.map((u) => String(u.id)));
          this.results = users.filter((u) => !chosen.has(u.id()));
          m.redraw();
        })
        .catch(() => {});
    }, 300);
  }

  addUser(user) {
    if (!this.users.some((u) => String(u.id) === user.id())) {
      this.users.push({ id: user.id(), name: user.displayName(), avatarUrl: user.avatarUrl() });
    }
    this.query = '';
    this.results = [];
  }

  removeUser(id) {
    this.users = this.users.filter((u) => String(u.id) !== String(id));
  }

  submit() {
    if (this.mode === 'all') {
      this.attrs.onsave([], []);
      this.hide();
      return;
    }
    if (this.groupIds.size + this.users.length === 0) {
      this.error = app.translator.trans(T + 'empty_error');
      return;
    }
    this.attrs.onsave([...this.groupIds], this.users);
    this.hide();
  }
}
