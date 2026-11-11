# Testing and Verification

This document provides a practical checklist for verifying Restricted Visibility in a Flarum 2.x installation.

Run these checks on a staging forum with a recent backup before deploying the extension or upgrading it in production. Use separate test accounts; an administrator account alone is not sufficient because administrators have unrestricted access.

> This checklist is a documentation aid, not a formal security certification or a guarantee that every possible integration is covered. Adapt it to the installed Flarum version and extensions.

## 1. Test accounts and permissions

Prepare the following accounts:

- **Administrator:** full administrative access.
- **Moderator:** has the extension's `View all restricted content` permission.
- **Author:** has permission to restrict their own posts and discussions.
- **Recipient:** is explicitly selected as an allowed user.
- **Group recipient:** belongs to a group selected as an allowed group.
- **普通 member:** has no restriction or bypass permission and is not an allowed recipient.

Confirm that the permissions are assigned only to the intended groups.

## 2. Restrict a new discussion

1. Sign in as the author.
2. Create a discussion and use the visibility control in the composer.
3. Select one recipient and, separately, one allowed group in additional test cases.
4. Publish the discussion.
5. Confirm that the author, selected recipient, group recipient, moderator, and administrator can view it.
6. Confirm that an unauthorized member cannot discover or open the restricted discussion.

## 3. Restrict an individual post

1. Create a public discussion.
2. Add a post restricted to a selected user or group.
3. Confirm that authorized users can read the post.
4. Confirm that unauthorized users cannot read it.
5. Confirm that the discussion's first post cannot be restricted independently, if that is the intended behavior.

## 4. Check visibility in different places

For an unauthorized member, check all applicable locations:

- Discussion page and post stream.
- Discussion list and discussion counts.
- Search results.
- User profile activity and post listings.
- API responses used by the installed Flarum frontend.
- Any custom pages or integrations that display posts.

Check that restricted content is not exposed in response bodies, snippets, metadata, or related resources.

## 5. Check permissions and editing

Test the following cases:

- An author can change restrictions on their own content when permitted.
- A user without the required permission cannot create or change restrictions.
- A regular author cannot change restrictions on another user's content.
- A moderator with the bypass permission can access and manage restricted content as intended.
- Editing an existing post still respects Flarum's normal edit permission and time limits.
- Removing a recipient or group removes their access as expected.

## 6. Check notifications and email

Use test accounts with discussion subscriptions and notification preferences configured.

Test relevant events, such as:

- A reply to a discussion containing restricted content.
- A mention involving a restricted post.
- Notifications generated for recipients who are not allowed to see the relevant content.
- Both in-forum notifications and email notifications, where enabled.

Inspect actual notification and email content. Unauthorized recipients must not receive restricted post text, excerpts, or other sensitive details.

## 7. Check real-time behavior and integrations

If Pusher or `flarum/realtime` is enabled, inspect the real-time behavior and event payloads to ensure restricted content is not disclosed.

Separately test third-party extensions and external integrations that may read posts, including:

- Search/indexing services.
- RSS or feed generators.
- Export tools.
- Bots and custom API clients.
- Analytics or moderation integrations.

Do not assume that every third-party extension automatically respects this extension's visibility rules.

## 8. Check discussion metadata

Verify what unauthorized users can see about discussions containing restricted posts.

In particular, inspect:

- Post counts.
- Last-post date.
- Last-post author.
- Discussion ordering and activity indicators.

Some denormalized discussion metadata may reflect restricted posts even when the post content itself is hidden. Consider restricting the entire discussion where this information is sensitive.

## 9. Check data removal

Perform destructive tests only on staging and with a restorable backup.

For each supported removal mode, verify the actual result:

- `hide`: restricted content is hidden as documented and remains available to authorized moderators.
- `delete`: selected restricted content is permanently removed.
- `public`: restrictions are removed and the affected content becomes public.

Verify that the confirmation prompt requires the exact expected confirmation text: `ELIMINA`.

If testing the CLI command, first use `--dry-run` and review its summary. Do not use `--yes` until you have verified the selected mode and target data.

Also verify the extension's data cleanup and migration reset procedure before uninstalling the package.

## 10. Regression checks

After testing or upgrading, confirm that:

- Public discussions and posts remain accessible as before.
- Normal Flarum permissions still work.
- Users can create, edit, and reply to content according to their existing permissions.
- No unexpected errors appear in the browser console or Flarum logs.
- The administration interface remains usable.
- The extension's English and Italian translations load correctly.

## Test report

For each test, record:

- Flarum version and PHP version.
- Extension version.
- Installed extensions relevant to the test.
- Account role used.
- Expected result.
- Actual result.
- Any relevant log entry or reproducible steps.

Do not publish private user data, email addresses, session tokens, API keys, or other secrets in public bug reports.

## Permissions: create private / make existing private / make public

Use non-admin accounts, each with one permission: A (*create new private*), B (*make existing private*), C (*make public*).

- [ ] A creates a new discussion and a new reply with limited visibility: allowed. A restricts an already published post: rejected.
- [ ] B restricts an already published post, and changes its recipients: allowed. B creates a new private post: rejected (no button in the composer).
- [ ] C removes the restriction ("Everyone") from a restricted post: allowed. C restricts a post or changes recipients: rejected.
- [ ] A or B removing a restriction: rejected, and the "Everyone" option is disabled in the modal.
- [ ] C edits the text of a restricted post without touching visibility: allowed, restriction unchanged.
- [ ] A user with none of the permissions sees no visibility controls and cannot send `restrictedGroupIds` / `restrictedUserIds` via the API.
- [ ] Same checks through the API directly (POST/PATCH with the fields), not only through the UI.
- [ ] After upgrading from 1.0.x, groups that could restrict also have *create new private* and *make public*.

## Deleted recipients (content must stay restricted)

- [ ] Restrict a discussion and a post to ONE group only. Delete that group. A user outside it still cannot see them; the author, a moderator with *View all restricted content* and an admin can.
- [ ] Same with ONE user as the only recipient, deleting the user.
- [ ] Restrict to a group and a user, delete only the group: still visible to the user, not to others.
- [ ] The badge/label shows the content as restricted, and the modal says nobody is left in the list.
- [ ] The author edits the text of such a post (author has *make public*): the post stays restricted.
- [ ] A user with *make public* chooses "Everyone" on it: it becomes public. A user without that permission cannot.
- [ ] After upgrading from 1.0.x, content that was already restricted is still restricted (markers were created for it).

## Performance

- [ ] With query logging on, an admin opening a discussion list or a long discussion with many restricted posts runs a constant number of queries for restriction rows and recipient names (no query per post).
