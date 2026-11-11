# Restricted Visibility for Flarum 2.x

[![Latest Stable Version](https://img.shields.io/packagist/v/peopleinside/flarum-restricted-visibility?label=Packagist)](https://packagist.org/packages/peopleinside/flarum-restricted-visibility)
[![Total Downloads](https://img.shields.io/packagist/dt/peopleinside/flarum-restricted-visibility)](https://packagist.org/packages/peopleinside/flarum-restricted-visibility)
[![License](https://img.shields.io/packagist/l/peopleinside/flarum-restricted-visibility)](https://github.com/PeopleInside/flarum-restricted-visibility/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/peopleinside/flarum-restricted-visibility)](https://packagist.org/packages/peopleinside/flarum-restricted-visibility)

Restrict the visibility of individual posts or entire discussions to selected users or groups in Flarum.

Users who are not authorized to view restricted content cannot access it through discussions, search results, or profiles. They also do not receive notifications or emails containing restricted content, even if they are subscribed to the discussion.

## Features

- Restrict individual posts or entire discussions.
- Select specific users and/or user groups who can access restricted content.
- Prevent unauthorized users from discovering restricted content through supported Flarum visibility mechanisms.
- Filter notifications and emails to prevent restricted content from being disclosed.
- Provide moderators with a permission-based visibility bypass.
- Manage and remove extension data through the administration interface or command line.
- English and Italian language support.

## Requirements

- Flarum 2.x
- PHP 8.3 or later

This extension is not compatible with Flarum 1.x.

## Screenshots

### Admin Interface

<img width="1645" height="900" alt="Screenshot 2026-11-11 122437" src="https://github.com/user-attachments/assets/225c645c-22ad-4cdd-9ba7-adb175b4dcf5" />

### Composing message interface
Flarum discussion composer interface. 
Note the crossed-out eye icon button positioned immediately before the 'Post Discussion' button. This control allows the user to toggle and activate visibility restrictions for the topic before publishing.

<img width="1110" height="341" alt="Screenshot 2026-11-10 233350" src="https://github.com/user-attachments/assets/a01f0a2e-0efd-44e0-9bc0-87f3343580f8" />

### When the eye icon is clicked on the composing window
you can see a Pop-up with options

<img width="382" height="286" alt="Screenshot 2026-11-10 233401" src="https://github.com/user-attachments/assets/2ed2b3c0-b1d9-49bb-9f2b-ad119c0022bc" />
 
...
 
<img width="395" height="654" alt="Screenshot 2026-11-10 233432" src="https://github.com/user-attachments/assets/73438810-93d1-47e0-8748-886c422f1a57" />

### Restricted topic and messages appearance

<img width="823" height="740" alt="Screenshot 2026-11-10 233556" src="https://github.com/user-attachments/assets/77a737e5-c342-457d-adfd-ccd2c9edb652" />

### Visibility option in the three dots menu

<img width="817" height="428" alt="Screenshot 2026-11-10 233621" src="https://github.com/user-attachments/assets/d55e8c3e-e6c4-42c5-94bf-e8df3616a483" />


## Installation

Run the following command in your Flarum installation directory:

```bash
composer require peopleinside/flarum-restricted-visibility:*
```

Then run the migrations and clear the cache:

```bash
php flarum migrate
php flarum cache:clear
```

Finally, enable the extension from the Flarum administration panel and configure its permissions.

## Permissions

Go to **Administration → Permissions** to configure the following permissions.

| Permission                                                    | Recommended users | Description                                                                                             |
| ------------------------------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------------- |
| Create new private posts and discussions                       | Trusted users     | Allows users to publish a **new** discussion or reply with limited visibility.                          |
| Make existing posts and discussions private                    | Trusted users     | Allows users to restrict content that is **already published**, or change who can see it.               |
| Make own posts and discussions public (remove restrictions)    | Trusted users     | Allows users to make their own restricted content visible again to everyone who can see the discussion. |
| View all restricted content                                    | Moderators        | Allows users to bypass visibility restrictions and manage restrictions created by others.               |

The permissions are independent and enforced on the server. For example, a user with only *create new private*
can publish restricted content but cannot restrict, change or undo an existing one.
Changing the recipients of already restricted content counts as *make existing private*.

Administrators always have all permissions.

No permissions are granted automatically to other groups.

> **Upgrading from 1.0.x:** the migration gives *create new private* and *make public* to every group that already had the restrict permission (now *make existing private*), so behavior does not change. Remove them from **Administration → Permissions** where you want a stricter setup.

## Usage

### Restricting new content

When creating a post or discussion:

1. Open the composer.
2. Click the crossed-out eye icon.
3. Select the users and/or groups allowed to view the content.
4. Publish the post or discussion.

### Changing visibility

To change the visibility of existing content, use the post or discussion menu and select **Edit visibility**, or use the visibility controls in the editing composer.

Changing the visibility of an existing post also requires permission to edit that post, including any applicable Flarum editing time restrictions.

### Visual indicators

A purple crossed-out eye icon indicates that a discussion contains restricted posts visible to the current user.

Users who are not authorized to see restricted content do not receive information about its existence. The restricted-content count is always zero for unauthorized users.

## Visibility rules

- Visibility can only be restricted, not expanded.
- The effective visibility of a post is the intersection of the discussion's visibility and the post's visibility.
- The author and users with the **View all restricted content** permission can always view restricted content.
- The first post of a discussion cannot be restricted independently. Restrict the entire discussion instead.
- Unauthorized users cannot see restricted posts or discover their existence through the extension's supported visibility mechanisms.
- Only the author and users with the visibility bypass permission can see the list of selected recipients.
- Authorized recipients see a restricted-content indicator.
- Users without the appropriate permissions cannot change restrictions created by others.
- **If every group and user a content was restricted to is deleted, the content stays restricted**: only its author, users with *View all restricted content* and administrators can see it, until someone with the *make public* permission publishes it. It never becomes public by itself.

## Security considerations

The extension implements several protections to help prevent unauthorized access to restricted content.

### Content visibility

Visibility scopes are applied to Flarum discussions and posts through `Extend\ModelVisibility`.

These scopes cover supported API responses, search results, counts, and relationships.

Posts also explicitly check the visibility of their parent discussion as an additional protection.

### Database queries and input validation

- Database queries use parameterized queries through the query builder.
- User and group identifiers are converted to integers.
- User and group selections are validated before being saved.
- Selection limits are enforced.
- Unauthorized write operations are rejected.

### Notifications

A centralized notification filter checks whether the recipient is allowed to access the notification subject and the related content.

The filter runs before notification delivery to the available notification drivers, including email.

This also takes into account notification blueprints that may contain multiple related posts or discussions.

### Data removal

Extension data removal is restricted to administrators.

The operation uses Flarum's CSRF protection and server-side confirmation validation. Content is processed in chunks of 100 models, each in its own short transaction; restriction links, permissions and settings are removed last. If it is interrupted, content not yet processed stays restricted and the command can simply be run again.

### Database relationships

Foreign keys use `ON DELETE CASCADE` so related records are removed when their posts, discussions, users, or groups are deleted. A separate restriction marker (`restricted_discussions`, `restricted_posts`) is not tied to recipients, so deleting the last allowed group or user does not make content public.

## Known limitations

Please consider these limitations when deploying the extension.

1. **Discussion metadata:** Flarum discussion metadata, such as post counts, last-post dates, and last-post authors, may reflect restricted posts. Unauthorized users might notice that a discussion has been updated or see the name of its latest author without being able to read the restricted content. For particularly sensitive discussions, restrict the entire discussion rather than individual posts.
2. **Third-party extensions and external services:** Extensions that retrieve posts through direct database queries or external indexes may not respect Flarum's visibility scopes. Examples include search integrations, RSS feeds, exports, bots, and external indexing services. Verify compatibility with each integration separately.
3. **Real-time events:** If you use Pusher or `flarum/realtime`, verify that real-time event payloads do not expose restricted post content. Content should be retrieved through the filtered API, but this behavior should be checked in your own environment.
4. **Previously delivered notifications:** Restricting content after publication cannot retract notifications that have already been sent or content that users have already read.
5. **Managing other users' content:** Users with the regular restriction permission can manage their own posts. Moderators with the visibility bypass permission can manage restrictions created by other users.
6. **Large forums:** Removing or processing a large amount of extension data through the administration interface may result in request timeouts. The command-line method is recommended for large forums.

## Testing and verification

Before deploying the extension or updating it, verify that its behavior is correct in your Flarum environment.

Use the checklist in [TESTING.md](TESTING.md) to verify:

- Access to restricted discussions and posts.
- Visibility in search results and user profiles.
- Permissions for authors, recipients, moderators, and administrators.
- Notification and email filtering.
- Editing and changing visibility.
- Real-time behavior, if enabled.
- Data removal and cleanup procedures.

Test with separate accounts representing users with different permissions. Do not rely exclusively on an administrator account, because administrators have unrestricted access.

Also verify compatibility with third-party extensions and integrations that retrieve or display discussion content.

## Complete removal

Disabling or uninstalling the extension without first processing its restricted content can make that content visible to everyone.

If you want to remove the extension completely, follow these steps in order.

### 1. Process restricted content

Go to:

**Administration → Extensions → Restricted Visibility → Danger Zone → Delete all extension data**

Choose what should happen to restricted content and enter the confirmation word:

`ELIMINA`

The available modes are:

| Mode | Result |
| --- | --- |
| `hide` (default) | Marks restricted content as hidden using `hidden_at`. Hidden content remains available to moderators. |
| `delete` | Permanently deletes restricted content. |
| `public` | Removes visibility restrictions and makes the content public. |

This operation also removes the extension's permissions and settings.

### 2. Disable the extension

After processing the restricted content, disable Restricted Visibility from the Flarum administration panel.

### 3. Remove extension data and migrations

Use the **Delete data** option on the extension administration page.

Alternatively, run:

```bash
php flarum migrate:reset --extension peopleinside-restricted-visibility
```

This removes the extension's database tables and migration records.

If you skip this step, empty database tables may remain.

### 4. Uninstall the package

Run:

```bash
composer remove peopleinside/flarum-restricted-visibility
```

### Command-line data removal

For larger forums, you can use the command-line purge command:

```bash
php flarum peopleinside-restricted-visibility:purge [--mode=hide|delete|public] [--actor=ID] [--dry-run] [--yes]
```

Available options:

| Option | Description |
| --- | --- |
| `--mode` or `-m` | Selects what happens to restricted content. Defaults to `hide`. |
| `--actor` | User ID recorded as the actor performing the hide operation. Defaults to the first administrator. |
| `--dry-run` | Displays a summary without applying changes. |
| `--yes` or `-y` | Skips the interactive confirmation. Without this option, enter `ELIMINA` when prompted. |

For large forums, the command-line method is recommended to avoid web request timeouts.

After completing the data removal, continue with steps 2–4 above.

> **Important:** Never disable or uninstall the extension before processing restricted content. Otherwise, content that was previously restricted may become publicly visible.

## Development

The JavaScript distribution bundles are included in `js/dist`.

If you modify the JavaScript source files, rebuild the bundles with:

```bash
cd js
npm install
npm run build
```

## Disclaimer

This extension is provided "as is", without warranties of any kind, express or implied.

The author makes no guarantees that the extension will be free from bugs, vulnerabilities, service interruptions, or compatibility issues with every Flarum installation or third-party extension.

You are responsible for:

- Testing the extension in your own environment before using it in production.
- Maintaining current backups of your forum and database.
- Reviewing permissions and access settings.
- Verifying compatibility with your installed extensions and integrations.
- Assessing whether the extension meets your security and privacy requirements.

The author is not liable for direct or indirect damages, data loss, unauthorized access, service interruptions, or other consequences resulting from the installation, use, or inability to use this extension, to the extent permitted by applicable law.

Nothing in this disclaimer excludes or limits liability where such exclusion or limitation is not permitted by applicable law.

## Support

For support, questions, or feedback, contact:

**[tickets@peopleinside-it.p.tawk.email](mailto:tickets@peopleinside-it.p.tawk.email)**

For bug reports and feature requests, you can also use the repository's [GitHub Issues](https://github.com/PeopleInside/flarum-restricted-visibility/issues).

## Links

- [GitHub repository](https://github.com/PeopleInside/flarum-restricted-visibility)
- [Packagist package](https://packagist.org/packages/peopleinside/flarum-restricted-visibility)

## License

This extension is released under the [MIT License](LICENSE).

## Author

Developed and maintained by **PeopleInside**.
