# Askly on Moodle — Deployment & Documentation

This repository documents the setup and customization of the Askly platform running on Moodle, including the theme and authentication plugin used, as well as the HTML content and assets that power the Askly landing experience.

---

## Overview
- **Platform:** Moodle
- **Theme:** Boost Magnific → https://moodle.org/plugins/theme_boost_magnific
- **Auth plugin:** Online Confirm → https://github.com/charbusch/moodle-auth_onlineconfirm
- **Custom content:** Askly home section and footer HTML

---

## Repository Layout
- **[html/](html/):** All HTML snippets to be pasted into Moodle.
  - [html/askly-home.html](html/askly-home.html) → Askly front-page hero & content section
  - [html/footer.html](html/footer.html) → Footer block with social links
- **[assets/images/](assets/images):** All images used by the content
- **[scripts/](scripts):** Any custom JavaScript used for Moodle (empty by default)

Notes:
- Keep images centralized in `assets/images/` and reference them via absolute URLs that are accessible by Moodle (e.g., uploaded to Moodle files or a CDN). The home page currently uses an external hosted image from the SdNOG wiki.
- If you add custom JS, place files in `scripts/` and include them via Moodle’s “Additional HTML” settings (see below).

---

## Prerequisites
- A working Moodle instance (v3.9+ recommended; theme/plugin support varies by Moodle version).
- Admin access to Site administration.
- SMTP/mail configured for your Moodle site to send confirmation emails (required by the `auth_onlineconfirm` plugin).

---

## Theme: Boost Magnific
1. **Install the theme:**
   - Option A — via UI:
     - Site administration → Plugins → Install plugins → Upload the theme ZIP and confirm.
   - Option B — via filesystem:
     - Extract the theme into `moodle/theme/boost_magnific` on your server.
     - Ensure correct permissions, then visit Site administration → Notifications to complete installation.
2. **Activate the theme:**
   - Site administration → Appearance → Themes → Theme selector → Choose "Boost Magnific" → Save.
   - Site administration → Development → Purge caches → Purge all caches.
3. **Optional configuration:**
   - Theme settings often allow custom SCSS, logo, brand colors, and footers. Adjust to align with Askly’s palette (primary accent: `#C41617`).

---

## Authentication: Online Confirm
1. **Install the plugin:**
   - Download from GitHub and place in `moodle/auth/onlineconfirm`.
   - Site administration → Notifications → Run upgrade.
2. **Enable and configure:**
   - Site administration → Plugins → Authentication → Manage authentication.
   - Enable "Online Confirm" and configure email settings (sender address, confirmation text, expiration, etc.).
3. **SMTP setup:**
   - Site administration → Server → Email → Configure `smtphosts`, `smtpuser`, `smtppass`, and related settings so Moodle can send confirmation emails.

### Email Delivery (Relay)
- **Current setup:** Email sending is relayed via the JINX mail relay.
- **Recommendation (from Nishal):** Stand up an SdNOG‑owned relay for better control and management, and potentially offer email relay services to the SdNOG community.
- **Operational tips:**
  - Publish SPF records to authorize the relay.
  - Sign outgoing mail with DKIM and enforce DMARC for domain protection.
  - Monitor bounce/feedback loops and rate limits.
  - Document credentials/rotation and restrict relay access to trusted sources.

---

## Adding Askly Content to Moodle
You can add the HTML content in two common, safe ways. Choose the one that best fits your site structure.

### Option A — Front page HTML block (recommended for the hero section)
- Navigate to the site front page → Turn editing on.
- Add a block → Choose "HTML".
- Edit the block’s content and paste the contents of [html/askly-home.html](html/askly-home.html).
- Position the block at the top of the page (e.g., in a full-width region if available in your theme).

### Option B — Appearance → Additional HTML (recommended for the footer)
- Site administration → Appearance → Additional HTML.
- Use the following fields:
  - **Footnote:** Paste the contents of [html/footer.html](html/footer.html). This places the Askly footer block site‑wide.
  - **Within HEAD / Before BODY ends:** If you need custom CSS or JS, place references or inline blocks here.
- Save and Purge caches after changes.

### Images and Asset Hosting
- The front page currently uses an image hosted on the SdNOG wiki. If you move assets locally:
  - Upload images to a public web path your Moodle can serve (e.g., via a repository or theme public assets).
  - Update any `<img src="..."></img>` references in [html/askly-home.html](html/askly-home.html) to point to the new absolute URLs.

Workaround used:
- We encountered an issue uploading pictures to the Moodle home page. As a workaround, images were uploaded to `wiki.sdnog.sd` and mapped to the home page via absolute URLs.
- The hero image in [html/askly-home.html](html/askly-home.html) references the wiki-hosted asset. If you later host images locally or on a CDN, update the `src` to your new public URL.

---

## Course Setup
This deployment hides the participants list from learners to improve privacy. Teachers and managers keep visibility.

### Remove participant visibility for students
- Option A — Site‑wide via role edit:
  - Site administration → Users → Permissions → Define roles → Edit "Student".
  - Uncheck capability "View participants" (`moodle/course:viewparticipants`).
  - Optionally also uncheck "View user profiles" (`moodle/user:viewdetails`) if you want to prevent viewing other users’ profiles.
  - Save changes and Purge caches.
- Option B — Per‑course override:
  - In the course → More → Permissions.
  - Override the "Student" role: set "View participants" to Prohibit.
  - Save.

Verification:
- Log in as a student in the course. The Participants link should not appear, and direct access to `/user/index.php?id=<courseid>` should be denied.

Notes:
- Staff roles (Teacher, Manager) retain participant visibility by default. Do not change these unless you have a specific need.

---

## Custom Scripts (if needed)
- Place any site-specific JavaScript files in [scripts/](scripts) and host them from a public URL (or include inline JS cautiously).
- To include JS site‑wide:
  - Site administration → Appearance → Additional HTML → "Before BODY is closed" → include a `<script src="https://your-host/scripts/yourfile.js"></script>` tag, or paste small inline scripts.
- Keep scripts minimal and avoid interfering with Moodle core or Boost Magnific behaviors.

---

## Content Guidelines
- **Branding:** Accent color `#C41617` (SdNOG red). Maintain typography and tone consistent with Askly communications.
- **Accessibility:**
  - Use semantic HTML, descriptive `alt` text for images, and sufficient contrast.
  - Verify keyboard focus order and test with screen readers where possible.
- **Performance:**
  - Host images optimized (WebP/PNG, proper dimensions). Avoid large inline SVGs or blocking JS.
  - Minimize inline styles; prefer theme SCSS where available.

---

## AI‑Generated Assets
We used AI tools to generate some of the Ramadan Challenge pictures and theme‑related visuals.

- **Scope:** Hero images, posters, and theme visuals supporting the Askly experience.
- **Storage:** All final images are consolidated under [assets/images](assets/images).
- **Compliance:** Ensure assets are original or appropriately licensed; avoid using copyrighted third‑party logos or imagery without permission.
- **Workflow tip:** Consider saving prompts/version notes alongside assets (e.g., `assets/images/README.md`) to make future iterations easier.

---

## Maintenance
- **Editing content:** Update files under [html/](html) and re‑paste into Moodle where originally added.
- **Adding images:** Place new images in [assets/images/](assets/images) and host them at accessible URLs for Moodle.
- **Testing:**
  - Use a staging/sandbox Moodle to validate changes.
  - After updates, Site administration → Development → Purge caches.
- **Versioning:** Commit changes with clear messages and tag releases that map to live deployments.

---

## Troubleshooting
- **Theme not showing:** Confirm Boost Magnific is selected and caches are purged.
- **Emails not sent:** Verify SMTP settings and the `auth_onlineconfirm` configuration; check Scheduled tasks.
- **Layout issues:**
  - Some themes constrain block widths. Prefer full‑width regions or Additional HTML areas for hero sections.
  - Inspect with browser dev tools to adjust minor CSS within allowed theme settings.

---

## Quick Tasks
- Update hero copy/image → Edit [html/askly-home.html](html/askly-home.html), paste into the front page block, purge caches.
- Update social links or footer text → Edit [html/footer.html](html/footer.html), paste into Appearance → Additional HTML → Footnote.
- Add a new image → Place file in [assets/images/](assets/images) and update HTML to reference its public URL.

---

## Credits
- SdNOG Askly team
- Moodle community and plugin maintainers

