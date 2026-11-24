=== Nemvo Share ===
Contributors: fpoller
Tags: file sharing, secure share, password, burn after reading, private files
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Share notes and files through secret links, with passwords, expiry and burn after reading. Files are encrypted on your server.

== Description ==

Nemvo Share lets you send notes and files from wp-admin through a secret link such as `https://example.com/share/k3XwP9…/`. You choose who can open it, for how long, and whether it destroys itself after reading. Everything stays on your own server: there is no file-sharing service in between.

= Notes, files or both =

* Write a note (credentials, instructions, a message), attach files, or both.
* Drag and drop files, with a progress bar, speed and time left for every file.
* Files of any size: uploads are sent in pieces smaller than your server's upload limit, so a 2 MB PHP limit doesn't stop a 2 GB file. The upload limits and free disk space are shown where you upload.

= Who can open it =

* **Anyone with the link**, or **link and password**, chosen per share.
* Strong passwords are generated for you (like `Kp7w-M3qa-xT9e-Vh4c`, easy to read out), or type your own.
* Recipients enter the password in a normal form on your site, not a browser pop-up. Five wrong passwords pause attempts for 15 minutes.
* "Copy link", "Copy password" and "Email link" buttons, so the link and password can go by different routes.

= Time limits and burn after reading =

* Available for 1 hour, 1 day, 7 days, 30 days, until a date you pick, or without a limit.
* **Burn after reading:** the share can be opened once. The recipient sees an Open button first, so link previews in chat and email apps don't use it up. After opening, that browser has a set time (60 minutes by default) to read the note and download the files. The share is destroyed as soon as every file has been downloaded, or when the time is up. A note on its own is destroyed the moment it is shown.
* **Delete contents when it ends:** files and note are deleted from the server when the share expires or is burned. Without it they are kept, so you can extend or reopen the share.
* Expiry and burn after reading are enforced on every request; an hourly clean-up deletes contents even when nobody visits the link.

= Looks like your site =

* Shares open inside a normal page of your site, so they use your theme's header, footer, fonts, colours and buttons, in block and classic themes alike.
* The recipient sees the title (with a password, only after entering it), the note with a copy button, each file with a download button next to its name, and the share's settings: when it expires, burn after reading, password protection and whether it is deleted afterwards.
* Edit the share page to add your own text around the share, or move the "Shared notes and files" block to another page.
* One setting for the button colour; everything else follows your theme. Themes can restyle it with CSS custom properties or override the template.

= Secure by design =

* Files are encrypted on disk with XChaCha20-Poly1305, with a key per file, stored under random names in a folder the web server refuses to serve (Apache and IIS rules are added for you; Site Health checks it and shows the nginx rule when needed). Even a file fetched past the web server is unreadable.
* Notes, links and passwords are encrypted in the database. Add `NEMVO_SHARE_KEY` to wp-config.php and shares created from then on can't be decrypted from a copy of the database alone.
* Secret links have 143 bits of randomness and are looked up by hash.
* Share pages are never cached, never indexed by search engines and never leak their address through the Referer header.
* Downloads are always sent as attachments, never shown inline, and support resuming.
* Passwords are hashed with WordPress's password hashing; access cookies are HttpOnly, signed and limited to the share's own path.

= For administrators =

* A list of all shares with status, contents, expiry and how often each was opened and downloaded.
* Preview a share as recipients see it, without opening it or using up burn after reading.
* Activity per share: opened, downloaded, wrong passwords, expired, burned. Who opened a share is not recorded, only when (see Privacy below for the short-lived IP hash used against password guessing).
* Optional email the first time a share is opened.
* Switch a share off, give it a new link (the old one stops working), or reopen a burned share whose contents were kept.
* WP-CLI: `wp nemvo-share create`, `list`, `delete` and `cleanup`.

== Installation ==

1. Upload the plugin under **Plugins › Add New › Upload Plugin** and activate it.
2. A page called **Share** is created for you; links open under `/share/…`.
3. Go to **Shares › Add New Share**, add a note or files, and create the share.
4. Optional: open **Shares › Settings** and copy the `NEMVO_SHARE_KEY` line into wp-config.php.

Pretty permalinks give links like `/share/…`. With plain permalinks, links still work but contain `?page_id=…`.

== Frequently Asked Questions ==

= Can people get to the files without the link? =

No. Files are only handed out by the share page after its checks (password, expiry, burn after reading). They are stored encrypted under random names, in a folder with rules that refuse direct web requests. Settings and Site Health show whether your web server honours those rules.

= My server runs nginx. What do I need to do? =

Add a deny rule for the storage folder to your nginx site configuration (inside the `server { … }` block, before any `location ~ \.php$` block), then reload nginx:

`location ~* /uploads/nemvo-share-[a-z0-9]+/ { deny all; }`

Why: the plugin keeps files in `wp-content/uploads/nemvo-share-<random>/` and puts `.htaccess` and `web.config` files there that make Apache and IIS refuse direct requests. nginx ignores those files, so without the rule anyone who knew or guessed a stored file's address could fetch it straight from the web server, around the share page's checks (password, expiry, burn after reading).

The files stay encrypted regardless: they have random names without extensions, and each is encrypted with its own key that only the share page uses. A file fetched directly is unreadable, so the rule is a second line of defence, not the only one. **Shares › Settings** and **Tools › Site Health** test the folder with a real request and show the rule when it is missing.

You can also keep the files outside the website altogether by defining `NEMVO_SHARE_STORAGE` in wp-config.php with the path of a writable folder.

= Why can any type of file be shared? =

Shares are for sending files to people, so the plugin doesn't limit types the way the Media Library does. A shared file is never executed or shown by the site: it is encrypted on disk under a random name without an extension, and only ever sent as a download (`application/octet-stream`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`, `Content-Disposition: attachment`). Only users who can manage shares can upload. To refuse some files anyway, return `false` or a `WP_Error` from the `nemvo_share_allow_file` filter (it receives the file name, size and MIME type).

= What does NEMVO_SHARE_KEY do? =

Without it, the encryption key lives in the database next to the encrypted notes and file keys. With `define( 'NEMVO_SHARE_KEY', '…' );` in wp-config.php, new shares are encrypted with a key kept out of the database, so a leaked database backup alone can't decrypt them. The settings page has a random line ready to copy. Shares created before you add it stay readable with the key in the database (and keep that weaker protection until they are deleted). Keep a copy of the line: shares created with it can't be opened without it.

= Who sees the title of a share? =

Without a password, anyone with the link. With a password, nobody until the right password is entered: the page heading, the browser tab and the rest of the page say "Protected share" instead.

= What happens when a chat app previews a burn-after-reading link? =

Nothing. Opening the link shows the title (or "Protected share" when there is a password), the settings and an Open button. Only pressing Open uses up the share, and link previews don't press buttons.

= The recipient's download was interrupted. =

Downloads can be resumed. With burn after reading, the browser that opened the share can download again until every file has been downloaded completely once, or until its time is up.

= Can I change how the share page looks? =

Edit the share page like any other page. For colours, set the button colour under Settings, or add CSS such as `.nvs-share { --nvs-accent: #0a7d48; --nvs-radius: 4px; }`. To change the markup, copy `templates/share.php` to `your-theme/nemvo-share/share.php`.

= Who can create shares? =

Administrators (the `manage_options` capability). Use the `nemvo_share_capability` filter to allow another capability.

= Does it work behind a reverse proxy? =

Yes. For the password rate limit to see visitors' real addresses, return the forwarded address from the `nemvo_share_client_ip` filter.

= What is removed when I delete the plugin? =

The scheduled clean-up always. Everything else only if you tick "Also delete all shares, their files and these settings" in the settings: then all shares, files, the share page, the settings and the "shares per page" screen option are removed. On a multisite network each site is handled according to its own setting.

== Privacy ==

* **Access cookie.** After the right password, or after opening a burn-after-reading share, the browser gets a cookie `nemvo_share_<id>`, limited to that share's address, HttpOnly and signed. It holds an expiry time, a signature and, for burn after reading, a random value; nothing about the person. It lasts at most 12 hours by default (Settings), never past the share's end.
* **Password attempts.** To limit guessing, each password attempt is logged with a keyed hash (HMAC with the site's salts) of the visitor's IP address. The IP address itself is never stored. The hash is removed after 15 minutes, by the next hourly clean-up at the latest; the attempt itself stays in the share's activity log without it.
* **Activity.** Views and downloads are counted and logged with their time, without recording who.
* **Share page.** Activation creates a published page called "Share" (slug `share`) where links open. It is left out of menus, page lists, sitemaps and search engines.
* **Notifications.** When "email me" is on for a share, the creator gets one email when it is first opened. It goes through the site's normal `wp_mail()`.
* The plugin adds suggested text to **Settings › Privacy**.

== External services ==

This plugin does not connect to any external service. Nothing is sent to the plugin's authors or to third parties, and all scripts, styles and icons are bundled.

The only outgoing request goes to the site itself: the storage check (in Settings, Site Health, at most once a day, or when you click "Check again") requests a test file from the storage folder through the site's own URL, to see whether the web server refuses it.

== Screenshots ==

1. Creating a share: note, files with upload progress, password and expiry.
2. The share is ready: copy the link and password, preview, or email the link.
3. What the recipient sees: title, settings, note and downloads, in your theme.
4. The password form.
5. A burn-after-reading share waiting to be opened.
6. All shares with status, contents and activity.

== Changelog ==

= 1.0.2 =
* Fixed: share titles are limited to 200 characters everywhere, also when a share is saved without the editor (for example with WP-CLI). A longer title used to make the save fail.
* Fixed: an expiry date that doesn't exist (such as 31 February) is refused with a clear message instead of being moved to another day.
* WP-CLI: `wp nemvo-share create --expires=` refuses dates in the past.

= 1.0.1 =
* The title of a password-protected share is now hidden until the password is entered (page heading, browser title, download form); the page says "Protected share" instead.
* Privacy: the IP hash kept with password attempts is now removed after 15 minutes, as the privacy text says; privacy text and readme corrected.
* Settings can now be saved by users who get access through the `nemvo_share_capability` filter.
* Many wrong passwords for one share now answer 429 instead of slowing the server down.
* REST hardening: upload routes are left out of the public REST index, and anonymous requests get 401 before any parameter checks.
* New `nemvo_share_allow_file` filter to refuse some files.
* Rewrite rules: flushed only when the share page changes (with a daily check), and removed on deactivation.
* Site Health uses the storage check from the last day instead of making a request on every visit.
* Uninstall handles multisite and also removes the "shares per page" screen option.
* Requires WordPress 6.3 (the share block uses block API version 3).
* Coding standards and Plugin Check clean, including warnings.

= 1.0.0 =
* First release: notes and files through secret links, with optional passwords, expiry, burn after reading and automatic deletion, encrypted storage, chunked uploads with progress, a theme-friendly share page, activity log, Site Health checks and WP-CLI commands.

== Upgrade Notice ==

= 1.0.2 =
Stricter checks of share titles and expiry dates.

= 1.0.1 =
Hides the title of password-protected shares until the password is entered, removes stored IP hashes after 15 minutes, and fixes saving settings with a custom capability. Requires WordPress 6.3.
