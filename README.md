# Askly on Moodle — Deployment & Documentation

This repository documents the setup and customization of the Askly platform running on Moodle, including the theme and authentication plugin used, as well as the HTML content and assets that power the Askly landing experience.

---

## Overview
- **Platform:** Moodle 5.1
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
- Moodle 5.1 instance.
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
  - Primary interface color: `#232323`.
  - Footer background color: `#FFFFFF`.
   - Footer alignment tip (Boost Magnific): to center footer content across blocks, add the following transparent placeholder to Footer Block 1 and Footer Block 3 in the theme footer setup:
     
     ```html
     <p style="text-align: center;"><code aria-hidden="true" style="opacity:0;color:transparent;background:transparent;border:0;pointer-events:none;">.</code></p>
     ```
     
     This placeholder is fully transparent and non-interactive, ensuring consistent centering when certain regions require content to balance the layout.

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

## Home Page Design Prompt
The following prompt was used to generate and refine the home page HTML/CSS and UI/UX decisions.

```text
I have a draft HTML layout for our 'Askly' platform (the SdNOG Ramadan Challenge), but the UI/UX needs a significant upgrade to look like a modern, professional tech-education platform.

The current code has the basic structure (a hero section, an image column, and a text column), but it lacks proper styling, spacing, and brand integration. Our target audience consists of network and security professionals, so the design needs to be sleek, responsive, and highly readable.

Please update the provided HTML/CSS based on the following UI/UX requirements:

1. Brand Colors & Theme (Dark Mode Preferred):

Background: Implement a dark theme (e.g., #121212 or deep charcoal) to reduce eye strain and give it a 'tech' feel.

Text: Crisp white or light grey (#E0E0E0) for primary readability.

Primary Accent: Use the exact SdNOG red (#C41617) for all primary actions, buttons, hover states, and key highlights.

2. Typography & Hierarchy Refinement:

The current HTML imports many fonts (Roboto, Open Sans, Montserrat, etc.). Please consolidate this. Choose one clean, modern sans-serif font for the body (like Roboto or Inter) and one bold font for headings (like Montserrat). Remove the unused @font-face declarations to improve page load speed.

H1 (<h1 id="iqlvf">): Make "Welcome to Askly – Where Curiosity Meets Opportunity!" much larger, bolder, and visually distinct.

Subtitles: "Learn. Challenge. Win!" should be styled as an eye-catching accent, perhaps using the SdNOG red.

Remove unnecessary <br/> and empty <p> tags that are currently cluttering the code and use CSS margin and padding for spacing instead.

3. Layout & Spacing (Flexbox/Grid):

Ensure the <div id="i89tw" class="row align-items-center"> utilizes proper CSS Flexbox or Grid.

On Desktop: The image should be on the left (or right) taking up 50% width, with the text perfectly vertically centered on the opposite side.

On Mobile: The layout must stack cleanly (image on top, text below) with adequate padding (at least 20px on the sides).

4. Actionable UX Elements:

The image currently links to the course (<a href="https://askly.sdnog.sd/course/view.php?id=3">). Change this into a clear, high-contrast Call-to-Action (CTA) button placed beneath the text description.

The button should say "Start Today's Challenge" and use the #C41617 red with a subtle hover effect (e.g., brightening the red or adding a slight drop shadow).

5. Content Styling (The Rules):

The text explaining the rules ("Every day, we will release a new question...") needs to be broken out of standard <h3> tags.

Style these as Card UI elements or a visually distinct list with icons (e.g., a calendar icon for the daily release, a trophy icon for the winners) to make it scannable.

Please provide the updated HTML with an embedded or external CSS stylesheet that reflects these modern UI/UX principles.
```

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

