# Publishing to WordPress.org

How Nemvo Share gets into the WordPress.org plugin directory, and how new versions reach it afterwards.

## 1. Before submitting

- **WordPress.org account.** The account that submits becomes the plugin's owner. `Contributors:` in `nemvo-share/readme.txt` must list WordPress.org usernames (currently `fpoller`).
- **Two-factor authentication.** WordPress.org requires 2FA for accounts with commit access. Turn it on under *Edit profile → Account & Security*.
- **Checks.** Build the zip and run the smoke test. The smoke test includes Plugin Check, the same tool the review team runs first:

  ```sh
  ./build.sh
  ./smoke-test.sh dist/nemvo-share-<version>.zip
  phpcs --standard=phpcs.xml.dist
  ```

  Optionally, paste `readme.txt` into the [readme validator](https://wordpress.org/plugins/developers/readme-validator/).

Plugin Check reports no errors and no warnings, and the code passes the WordPress Coding Standards and PHPCompatibilityWP (`phpcs.xml.dist`, also run in CI).

## 2. Submit

1. Sign in at [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/).
2. Upload `dist/nemvo-share-<version>.zip` and accept the guidelines.
3. The slug is taken from the plugin name: `nemvo-share`. It shows on the confirmation page. You can still ask the reviewers to change it, but once the plugin is approved it is permanent.

An automated scan runs right away. Then the plugin waits in the review queue; the page shows how many plugins are ahead of it. Reviews take anywhere from a few days to several weeks.

## 3. During review

The review team emails you from `plugins@wordpress.org`. Reply to that email, and don't open a new thread. If they ask for changes:

1. Make the changes and **raise the version** (a patch bump, such as 1.0.1 → 1.0.2): every change gets a new version, also while the plugin is in review, so each uploaded zip can be told apart. Add a changelog entry to `readme.txt` and `README.md` (see *Later releases* for every place the version lives).
2. Run `./build.sh` and `./smoke-test.sh dist/nemvo-share-<version>.zip` again.
3. Upload the new zip on the same [Add your plugin](https://wordpress.org/plugins/developers/add/) page, then reply to the email saying what you changed.

Reviewers often ask about the following. The answers are already in `readme.txt`:

| Topic | Where it's answered |
|---|---|
| Calls to external services | None (*External services* section). The plugin only makes one request to the site itself: the storage protection check in Settings and Site Health, at most once a day. It uses `sslverify` from core's `https_local_ssl_verify` filter, like core's loopback checks. |
| Privacy | *Privacy* section and the suggested policy text: access cookie, an HMAC of the IP address on password attempts (removed after 15 minutes by `Access::forget_visitors()`), the "Share" page created on activation. |
| Minified or third-party code | None; all JavaScript and CSS is the plugin's own, unminified source. |
| Licenses | MIT for the plugin. |
| Uploads of any file type | Only users with `manage_options` (or the filtered capability) can upload. Files are encrypted on disk under random names without extensions, never executed or served inline, and only sent as `application/octet-stream` attachments with `nosniff` and a sandboxing CSP. FAQ *Why can any type of file be shared?*; sites can restrict types with the `nemvo_share_allow_file` filter. |
| REST routes | `nemvo-share/v1/uploads…`, all with `permission_callback` = the manage capability, hidden from the REST index. Arguments are validated inside the callbacks, so anonymous requests get 401 before any parameter check. |
| `flush_rewrite_rules()` | Only on activation and once after the share page changes (an option flag; plus a check at most once a day that the rules exist). Deactivation deletes the stored rules instead of flushing. |
| Unsanitized note and password input | Notes are encrypted and always escaped on output; they keep `<` and `>` so code and passwords survive. Passwords are hashed and compared exactly as typed. Both are explained in comments next to the code (`Admin::sanitize_note()`, `Frontend::unlock()`). |
| Direct file operations (`fopen`, `fwrite`) | Files are streamed and encrypted block by block, which WP_Filesystem can't do. Annotated in `class-storage.php` and `class-uploads.php`. |
| What it adds to the directory (file sharing is a crowded category) | Self-hosted: no file-sharing service in between and no external requests. Files encrypted at rest with a key per file (XChaCha20-Poly1305), notes and passwords encrypted in the database, optional key outside the database (`NEMVO_SHARE_KEY`). Burn after reading that link previews in chat and email apps can't trigger (an Open button; downloads count only when complete). Chunked uploads past PHP's upload limit. The share page uses the site's own theme. |
| Limits on input | Titles at most 200 characters (`Shares::TITLE_MAX`), file names 200, MIME types 150; expiry dates must be real dates in the future (`Admin::expiry_from_local()`); upload size within the configured maximum and free disk space, chunks in order and of the exact size; burn window, unlock time and record retention clamped to fixed ranges (`Settings::sanitize()`); five password attempts per visitor per 15 minutes; activity capped at 200 entries per share. |

## 4. After approval

You get Subversion (SVN) access to `https://plugins.svn.wordpress.org/nemvo-share/`. SVN doesn't use your normal password: set an SVN password under [Edit profile → Account & Security](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password).

The plugin goes live when the approved version is committed to SVN. Use one of the two ways below, not both.

### Option A: GitHub Actions (recommended)

The release workflow (`.github/workflows/release.yml`) already contains a WordPress.org job, which only runs once you switch it on. In the repository on GitHub:

1. **Settings → Secrets and variables → Actions → Secrets:** add `SVN_USERNAME` (your WordPress.org username) and `SVN_PASSWORD` (the SVN password).
2. **Settings → Secrets and variables → Actions → Variables:** add `WPORG_DEPLOY` with the value `true`.
3. Push the tag of the approved version (`git tag -a v<version> -m "Nemvo Share <version>" && git push origin v<version>`), or, if it was already pushed, open its run under **Actions → Release** and choose **Re-run all jobs**.

The job builds the release zip, copies its contents to `trunk/` (so WordPress.org gets exactly the files in the zip), creates `tags/<version>` and uploads `.wordpress-org/` (icons, banners, screenshots) to `assets/`. Once `WPORG_DEPLOY` is on, **every pushed tag is a public WordPress.org release**.

### Option B: by hand with SVN

```sh
unzip -q dist/nemvo-share-<version>.zip -d build
svn checkout https://plugins.svn.wordpress.org/nemvo-share wporg && cd wporg
rsync -a --delete ../build/nemvo-share/ trunk/
mkdir -p assets && cp ../.wordpress-org/* assets/
svn add --force trunk assets
svn cp trunk tags/<version>
svn propset svn:mime-type image/png assets/*.png
svn propset svn:mime-type image/svg+xml assets/*.svg
svn commit -m "Nemvo Share <version>" --username <wordpress.org username>
```

The listing at `https://wordpress.org/plugins/nemvo-share/` updates within a few minutes. Search can take a few hours to pick it up.

## 5. Later releases

1. Set the new version in `nemvo-share/nemvo-share.php` (header and `NEMVO_SHARE_VERSION`), in `readme.txt` (`Stable tag:`) and in `blocks/share/block.json`. Add a changelog entry to `readme.txt` (plus an Upgrade Notice) and `README.md`. `build.sh` refuses to build when these differ.
2. If the database schema changed, raise `Install::DB_VERSION` (`dbDelta` adds new columns and keys on the next page load).
3. If strings changed, regenerate `languages/nemvo-share.pot`:
   `docker compose -f .devcontainer/docker-compose.yml exec -T wordpress wp i18n make-pot /var/www/html/wp-content/plugins/nemvo-share /var/www/html/wp-content/plugins/nemvo-share/languages/nemvo-share.pot --domain=nemvo-share`
4. Run the tests (README → Tests): `cd tests/e2e && npm test`, `phpcs --standard=phpcs.xml.dist`, `./build.sh` and `./smoke-test.sh dist/nemvo-share-<version>.zip`.
5. Commit, tag and push:

   ```sh
   git tag -a v<version> -m "Nemvo Share <version>"
   git push origin main v<version>
   ```

The release workflow creates the GitHub release with the zip, and, with `WPORG_DEPLOY` on, commits the version to WordPress.org. Sites that have the plugin installed are then offered the update in wp-admin.

When a new WordPress version comes out, test the plugin with it and raise `Tested up to:` in `readme.txt`. Release this as a normal patch version, or commit only `readme.txt` to SVN `trunk/` and `tags/<current version>/`.

## Assets

`.wordpress-org/` holds what the directory shows besides the readme:

| File | Size | Shown as |
|---|---|---|
| `icon.svg`, `icon-256x256.png`, `icon-128x128.png` | 256 and 128 px | Plugin icon |
| `banner-1544x500.png`, `banner-772x250.png` | | Header banner |
| `screenshot-1.png` … `screenshot-6.png` | | Screenshots, captioned by `== Screenshots ==` in `readme.txt` |

They are generated from the dev container: `cd tests/e2e && npm install && node assets.mjs`. The script creates its own example shares and uses a neutral site title while it takes the pictures.
