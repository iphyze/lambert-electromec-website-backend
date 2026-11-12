# Lambert Electromec Backend

PHP + MySQL API for the Lambert Electromec website and admin portal.


## AI Phase 1 — Batch 1 foundation

Batch 1 adds the provider-independent AI knowledge layer only. It does **not** call OpenAI and does not add a chatbot UI.

- `ai_knowledge_entries` stores approved static EN/FR website knowledge copied verbatim from the current Public content.
- Projects, Divisions, Facility Management projects and Media Center records are retrieved live from MySQL; they are not copied into the AI table.
- AI retrieval enforces the existing `published` / `published_at` and `active` visibility rules.
- `includes/aiKnowledgeService.php` returns structured, source-linked records for later OpenAI grounding.
- Query length and retrieval limits are controlled by the `AI_KNOWLEDGE_*` environment values.

For an existing database, run `database/migrations/011_ai_foundation.sql` once. Fresh installations can import the current `database/schema.sql`.

## AI Phase 1 — Batch 2 OpenAI integration

Batch 2 adds the server-side OpenAI connection layer. It does **not** expose a public AI endpoint or chatbot UI yet.

- Uses the OpenAI Responses API through PHP cURL; no browser-side provider calls or API keys.
- `store=false` is enforced on every request.
- Default model is `gpt-5.6-luna` and is configurable with `OPENAI_MODEL`.
- Input/output limits, connection timeout, total timeout and retry count are environment-controlled.
- Provider errors are converted to safe application errors; prompts and API keys are not written to logs.
- Raw REST response output is parsed server-side into text/refusal/usage metadata.
- `scripts/testOpenAi.php` provides a CLI-only connection test.

Setup for Batch 2:

1. Copy the new AI values from `.env.example` into the backend `.env`.
2. Set `OPENAI_API_KEY` to the OpenAI Platform API key.
3. Set `AI_ENABLED=true` when ready to test.
4. Run `php scripts/verifySetup.php`.
5. Run `php scripts/testOpenAi.php` for one minimal live API test.

Keep `OPENAI_API_KEY` only in the PHP backend `.env`; never add it to the Public or Admin Next.js environment files.

## Production model

The public Next.js site remains a static export. CMS-managed Projects and Media Center content is fetched from this PHP API at runtime, so publishing from Admin does **not** require a Next.js rebuild. New project slugs will be handled by the runtime project-detail shell during the frontend integration batch.

## Backend Batch 1 foundation

- Apache front-controller routing
- environment configuration and MySQL connection
- API/CORS/security headers
- JWT access authentication + rotating HttpOnly refresh sessions
- CSRF protection
- login rate limiting and audit logs
- schema for administrators, divisions, projects/images, Media Center, Contact routing/submissions and protected CV attachments
- public Projects and Media Center APIs

## Backend Batch 2 content management

### Divisions

- `GET /admin/divisions`
- `POST /admin/divisions`
- `PATCH /admin/divisions/item?id=...`
- `DELETE /admin/divisions/item?id=...`
- `DELETE /admin/divisions/item?id=...&hard=true` — Super Admin only and only when unused

Division keys are stable identifiers and cannot be changed after creation. New divisions can be added without changing Projects API code.

### Projects

- `GET /admin/projects`
- `POST /admin/projects`
- `GET /admin/projects/item?id=...`
- `PATCH /admin/projects/item?id=...`
- `DELETE /admin/projects/item?id=...`
- `DELETE /admin/projects/item?id=...&hard=true` — Super Admin only
- `POST /admin/projects/status` — publish, unpublish, archive, restore
- `POST /admin/projects/images/upload` — multipart `files[]`
- `PATCH /admin/projects/images/item?id=...`
- `DELETE /admin/projects/images/item?id=...`
- `POST /admin/projects/images/reorder`

A project is created as a draft. Publishing requires an active division, English and French titles, at least one image and exactly one featured image. The first uploaded image is automatically featured.

### Media Center categories

- `GET /admin/media-categories`
- `POST /admin/media-categories`
- `PATCH /admin/media-categories/item?id=...`
- `DELETE /admin/media-categories/item?id=...`
- `DELETE /admin/media-categories/item?id=...&hard=true` — Super Admin only and only when unused

### Media Center items

- `GET /admin/media-center`
- `POST /admin/media-center` — multipart; document field name is `document`
- `GET /admin/media-center/item?id=...`
- `PATCH /admin/media-center/item?id=...`
- `DELETE /admin/media-center/item?id=...`
- `DELETE /admin/media-center/item?id=...&hard=true` — Super Admin only
- `POST /admin/media-center/file` — replace document; multipart `id` + `document`
- `POST /admin/media-center/status` — publish, unpublish, archive, restore

Publishing requires an active category plus English and French titles.

## Backend Batch 3 contact workflow

### Public contact API

- `GET /contact/subjects?lang=en|fr` — active subject keys/labels
- `POST /contact/submit` — multipart form submission

The submit endpoint matches the current Lambert frontend payload (`subject`, `full_name`, `email`, `phone`, `message`, `locale`, `terms_accepted`, `form_elapsed_ms`, `page_url`, optional Turnstile token and the Job Vacancy fields/CV).

Server-side controls include:

- trusted-origin enforcement
- honeypot and minimum-form-time checks
- per-IP and per-email rate limits
- duplicate submission suppression
- Cloudflare Turnstile verification
- complete server-side field validation
- Job Vacancy-only validation for position, location and CV
- protected PDF/DOC/DOCX CV storage, maximum 10 MB by default
- MIME/signature validation and randomized server filenames
- Terms acceptance timestamp
- Email delivery (Microsoft 365 Graph or SMTP) after the database transaction commits

Every valid submission is stored before email is attempted. A temporary email-provider failure therefore does not lose the submission. Email delivery status/history is recorded and can be retried by an administrator.

### Server-side recipient routing

The browser sends only a stable `subject` key. It never sends a destination email address.

- `GET /admin/contact-routing` — Admin/Super Admin
- `PATCH /admin/contact-routing/item?key=job-vacancy` — Admin/Super Admin; accepts `recipient_emails` and/or `cc_emails` arrays

The six current subject labels/keys remain seeded from the Lambert contact workflow. This routing endpoint changes email destinations only; it does not rewrite the client-provided labels.

### Contact administration

- `GET /admin/contact` — paginated search/filter list
- `GET /admin/contact/item?id=...` — submission, CV metadata and email delivery history
- `POST /admin/contact/status` — `read`, `replied`, `archive`, `restore`
- `GET /admin/contact/cv?id=...` — authenticated protected CV download
- `POST /admin/contact/email` — retry email delivery using the current database routing
- `DELETE /admin/contact/item?id=...` — Super Admin only

Editors cannot access contact submissions or CVs. Admin/Super Admin access is required. Permanent deletion removes the protected CV from disk after the database deletion succeeds.

### Batch 3 environment values

Configure production Turnstile and email (Microsoft 365 Graph and/or SMTP) values in `.env`. Production enforces Turnstile automatically, so `TURNSTILE_SECRET_KEY` and the allowed hostname(s) must be configured. Recipient addresses are configured through the database/admin routing endpoint, not in frontend code.

If you already imported the Batch 1/2 database schema, run `database/migrations/003_contact_workflow.sql` once. For a fresh database, import the current `database/schema.sql` instead.

## Email delivery: Microsoft 365 (Graph) or SMTP

Contact and careers notification emails can be sent through **Microsoft 365 (Graph API)** or **SMTP**. Microsoft 365 is the default. A Super Admin can switch provider in **Admin → System Settings → Email Delivery** (runtime setting `MAIL_TRANSPORT`), and "Reset" returns control to `.env` (or to the Microsoft 365 default when `.env` does not set it).

Credentials live in the server `.env` only and are never shown in the Admin:

```
# Microsoft 365 (Graph) – default provider
GRAPH_TENANT_ID=
GRAPH_CLIENT_ID=
GRAPH_CLIENT_SECRET=
GRAPH_SENDER=notification@lambertelectromec.com
GRAPH_SECRET_EXPIRES=2028-12-31      # shown in Admin, flagged 30 days before expiry
GRAPH_TIMEOUT_SECONDS=20             # optional
GRAPH_SAVE_TO_SENT_ITEMS=false       # optional

# Optional: choose the provider in .env instead of the Admin (graph | smtp)
# MAIL_TRANSPORT=graph
# MAIL_FALLBACK_TO_SMTP=false

# SMTP (unchanged; used when SMTP is selected or as the optional fallback)
SMTP_HOST=
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_AUTH=true
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_FROM_EMAIL=
SMTP_FROM_NAME=Lambert Electromec
```

Azure / Microsoft 365 prerequisites:

1. Register an app in Microsoft Entra ID and create a client secret (record its expiry date in `GRAPH_SECRET_EXPIRES`).
2. Add the Microsoft Graph **Mail.Send** *application* permission and grant **admin consent**.
3. Restrict the app to the sender mailbox with an Exchange Online Application Access Policy (otherwise Mail.Send allows sending as any mailbox in the tenant), for example: `New-ApplicationAccessPolicy -AppId <GRAPH_CLIENT_ID> -PolicyScopeGroupId notification@lambertelectromec.com -AccessRight RestrictAccess -Description "Lambert website mail"`.
4. Rotate the client secret before it expires and update `GRAPH_CLIENT_SECRET` and `GRAPH_SECRET_EXPIRES`.

Behaviour:

- CVs up to the 10 MB upload limit are supported; attachments above about 3 MB are sent through a Graph upload session automatically.
- **Fall back to SMTP if Microsoft 365 fails** (`MAIL_FALLBACK_TO_SMTP`, off by default) retries once through SMTP when SMTP is configured.
- Each delivery attempt records the provider used, shown in the contact message's delivery history. Run `database/migrations/018_mail_transport.sql` once on existing databases.
- **Send test email** in System Settings sends a short message to the signed-in Super Admin through the saved provider (max 5 per 15 minutes, audit-logged). From the command line: `php scripts/testMailTransport.php` (offline checks) or `php scripts/testMailTransport.php --send-to=you@example.com`.

## Website statistics (built-in, cookie-free)

The public website sends anonymous page views and a few named actions to `POST /analytics/collect`. Administrators see them in **Admin → Website Statistics** (and a 7-day summary on the Dashboard). Nothing is sent to third parties.

Privacy model:

- **No cookies or browser storage.** Unique visitors are counted with `sha256(daily salt | site host | IP | user agent)`. The salt is random, changes every day (Africa/Lagos) and is deleted after one day, so visitors cannot be identified or followed across days. IP addresses are never stored.
- **A visit** is the same anonymous visitor with no more than 30 minutes between page views. A **bounce** is a one-page visit with under 10 seconds of visible time.
- **Do Not Track / Global Privacy Control** visitors are not counted (setting, on by default). Bots, crawlers, monitoring tools and requests from other hosts are ignored.
- **Actions** recorded: contact enquiry sent, job application sent, CV uploaded, Media Centre document opened/downloaded (label = document reference), assistant question asked (never the text), phone tap (label = office code NG/GH/CI), email click, language switch, link to another website (label = domain). Form contents, names, emails and phone numbers are never sent.

Setup:

1. Run `database/migrations/019_website_analytics.sql`.
2. Optional `.env` values:
   ```
   # Public site hostnames allowed to send statistics (defaults to the hosts in ALLOWED_ORIGINS)
   ANALYTICS_ALLOWED_HOSTS=lambertelectromec.com,www.lambertelectromec.com,web.lambertelectromec.com.ng
   # Use Cloudflare's visitor country header (only when the site runs behind Cloudflare)
   ANALYTICS_TRUST_CLOUDFLARE_COUNTRY=false
   ```
3. Add a daily cron job that finalises daily totals and purges old detailed rows:
   ```
   15 2 * * * php /path/to/api/scripts/analyticsMaintenance.php >> /path/to/logs/analytics.log 2>&1
   ```
   (The collect endpoint also runs this occasionally, so statistics stay correct without cron, but cron is recommended.)
4. Rebuild and redeploy the public website and the admin portal.

Settings (Admin → System Settings → Website Analytics): collection on/off, Do Not Track, detailed-data retention (default 400 days; daily totals are kept permanently) and a per-visitor rate limit.

Tests: `php scripts/testAnalytics.php` (offline) and `php scripts/testAnalytics.php --with-db` (records sample data inside a transaction, checks the report, then rolls it back).

## Public API endpoints

All public content endpoints accept `?lang=en` or `?lang=fr`.

- `GET /health`
- `GET /projects/divisions`
- `GET /projects?page=1&limit=12&division=&search=&featured=false`
- `GET /projects/detail?slug=...`
- `GET /media-center/categories`
- `GET /media-center?page=1&limit=12&category=&search=&featured=false`
- `GET /media-center/detail?slug=...`
- `GET /contact/subjects?lang=en|fr`
- `POST /contact/submit`

Only published Projects/Media Center records whose optional `published_at` is not in the future are exposed publicly.

## Authentication and permissions

- `GET /auth/csrf`
- `POST /auth/login`
- `POST /auth/restore`
- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/me`

Editors can create and edit drafts. Admins/Super Admins can publish, unpublish and archive. Permanent deletion is restricted to Super Admins. Authenticated mutations require `X-CSRF-Token` matching the CSRF cookie.

## Upload policy

Project images:
- JPG, PNG, WEBP
- default maximum 8 MB per image
- default 10 files per request
- default 30 images per project

Media Center documents:
- PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, CSV, TXT
- default maximum 25 MB

Uploads use randomized server filenames. PHP/script execution remains blocked in upload directories. CV files remain protected from direct web access.

## Installation

1. Create a MySQL database.
2. Import `database/schema.sql`.
3. Copy `.env.example` to `.env` and configure production values.
4. Run `composer install --no-dev --optimize-autoloader`.
5. Ensure `uploads/projects`, `uploads/media-center` and `uploads/cv` are writable by PHP.
6. Configure Turnstile and email delivery (Microsoft 365 Graph by default, or SMTP) in `.env`, then run `database/migrations/018_mail_transport.sql`.
7. Create the first administrator with `php scripts/createAdmin.php`.
8. Configure recipient email(s) for each active Contact subject through the protected Admin routing API/admin portal.
9. Run `php scripts/verifySetup.php`.

For production use HTTPS, `APP_ENV=production`, `COOKIE_SECURE=true`, production-only `ALLOWED_ORIGINS`, long random secrets, a valid Turnstile secret/hostname configuration, and recipient routing for every active Contact subject.

## Admin dashboard endpoint

The admin portal dashboard uses:

- `GET /admin/dashboard`

The response is role-aware and includes Projects, Media Center, divisions, media categories, recent audit activity, contact-message counts for Admin/Super Admin accounts, and administrator counts only for Super Admin accounts.

For local admin development on port `5174`, add `http://localhost:5174` to `ALLOWED_ORIGINS`. A production admin subdomain such as `https://admin.lambertelectromec.com` must also be included.
