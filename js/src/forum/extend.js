import Extend from 'flarum/common/extenders';
import Discussion from 'flarum/common/models/Discussion';
import Post from 'flarum/common/models/Post';

// Attributi dei modelli: registrati con l'extender (Flarum 2.x), non sul prototype.
export default [
  new Extend.Model(Discussion)
    .attribute('isRestricted')
    .attribute('canRestrictVisibility')
    .attribute('restrictedGroupIds')
    .attribute('restrictedUsers')
    .attribute('restrictedPostCount'),

  new Extend.Model(Post)
    .attribute('isRestricted')
    .attribute('canRestrictVisibility')
    .attribute('restrictedGroupIds')
    .attribute('restrictedUsers'),
];
