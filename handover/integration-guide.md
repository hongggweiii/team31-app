# Integration guide: the code your app writes

You run our Python services as they are (`embedding-service/` and `matcher/`, and optionally `taxonomy-service/`). Your app only has to connect to them. That connecting code, the **glue**, is listed below. Every item is something team31-app does today; our version is in `app/` of the team31-app repository (CodeIgniter 4, for reading).

The glue's mistakes don't raise errors. If your profile text is built slightly differently, or a profile edit is never re-embedded, everything keeps working, but matches get quietly worse. That is why each item has exact rules, and why `testing/check.py` checks your output against ours.

**Our words:** a *showcase* is a provider's profile, and a *gig* is a hirer's job post. The engine's tables and fields use these names; yours can differ (`sql/README.md`, Step 1).

| # | Glue | When it runs | Our reference (in `app/`) |
|---|---|---|---|
| G1 | Turn a profile into the five text fields, its text and its hash | When embedding a profile | `Libraries/ProviderEmbedder.php` |
| G2 | Embed profiles and store the vectors | In a background job | `Commands/EmbedAuto.php`, `Commands/EmbedBatch.php`, `Libraries/EmbeddingStore.php` |
| G3 | Bump `updated_at` on every change that matters | Wherever your app changes profiles | — |
| G4 | Store the SkillsFuture tags chosen on your forms | When a gig or profile is saved | `Libraries/Taxonomy.php` (`check()`, `save()`), `Views/partials/taxonomy-picker.php` |
| G5 | Build the `/match` request for a gig, including its taxonomy array | When showing a gig's matches | `Libraries/MatcherClient.php` (`gigRequest()`), `Libraries/Taxonomy.php` (`tags()`) |
| G6 | Show the results, and handle every outcome without an error page | Same | `Libraries/MatcherClient.php` (`match()`), `Libraries/MatcherException.php`, `Controllers/Gigs.php` (`matches()`), `Views/gig-matches.php` |
| G7 | Configure the two service URLs and timeouts | Once | `Config/Embedding.php`, `Config/Matcher.php` |

**Optional.** Matching works without these. Build them only if you want what they give:

| # | Glue | When it runs | Our reference (in `app/`) |
|---|---|---|---|
| G8 | Record each match and show a matcher dashboard | After a matches page is sent; an admin page | `Libraries/MatchRecorder.php`, `Controllers/Dashboard.php`, `Views/dashboard.php` |
| G9 | "Suggest tags with AI" on your gig and profile forms | When a user clicks it; when the form is saved | `Controllers/TagSuggestions.php`, `Libraries/TaxonomyClient.php`, `Controllers/BaseController.php` (`attachSuggestedTerms()`), `Views/partials/taxonomy-picker.php` |
| G10 | A review page for new taxonomy terms, for reviewers only | A reviewer's page | `Controllers/TaxonomyDashboard.php`, `Views/taxonomy-dashboard/` (but add a role check: ours has none) |
| G11 | Show users the notifications a rejected term leaves them | Wherever your users see messages | — (our app writes them but doesn't show them yet) |

G9-G11 need the third, optional service, `taxonomy-service/`. It doesn't know about users or roles: your app enforces who may do what ("Roles for G9-G11", below).

The database tables come from `sql/`. The services' exact request and response formats are in `openapi/`. `integration-steps.md` puts all of this in order.

---

## G1. Profile → fields, text, hash

The engine embeds a profile from five text fields, in the format the ranking model was trained on. Map your profile onto them like this:

| Field | Comes from | How |
|---|---|---|
| `about_title` | The profile's one-line headline | Plain text, trimmed. It is **not** HTML-decoded: send the text the user typed. If your app stores it HTML-escaped (`&amp;`), decode it first |
| `about_description` | The bio | Rich text → plain text (below) |
| `services_offered_title` | The titles of the services offered, in display order | Each trimmed; empty ones dropped; joined with `"; "` |
| `services_offered_description` | The descriptions of those services, same order | Each rich text → plain text; empty ones dropped; joined with `"\n"` |
| `relevant_experience` | Achievements, in display order | Each rich text → plain text with its line breaks turned into spaces; empty ones dropped; joined with `" "` |

Not embedded: job title, years of experience, credentials, availability, rate, way of working, skills, tags, documents. A field with nothing in it is the empty string `""`.

If your profiles have no equivalent of a field (no services, say), leave it `""`. Matching still works on what is there, but less text means weaker matches. Our forms ask for: a headline of one line (up to 20 words); a bio of about 70-100 words on the specialisation and past roles; up to 3 services, best fit first; up to 5 achievements, one outcome each. Content like this resembles the model's training data most.

**Rich text → plain text** (what our forms' Quill editor produces: `p`, `br`, `strong`, `em`, `u`, `ol`/`ul`, `li`):
1. Each `<li>` becomes `- `; each `<br>`, `</p>` and `</li>` becomes a line break.
2. Strip the remaining tags, then decode HTML entities (UTF-8).
3. On each line, turn any run of whitespace (including non-breaking spaces) into one space, and trim.
4. Drop empty lines and lines that are only `-`. Join the rest with `"\n"`.

If your editor produces other HTML (`<div>` paragraphs, headings), convert it to this structure first. If a field is **plain text** (a textarea), pass it through `htmlspecialchars()` before `plainText()`. That way a literal `<` survives, and its lines and spaces are normalised the same way.

**The record** (`fields_json`): the five fields plus `years_experience`, an integer (whole years; round decimals down) or `null` if unknown. The matcher reads that number for one of its features, and treats `null` as 0. It is not embedded.

**The text**: the five fields in the order above, skipping empty ones, joined with one space. This is exactly what embedding-service embeds.

**The hash** (`text_hash`): the lowercase hex SHA-256 of the text's UTF-8 bytes. Your database connection must use `utf8mb4`, or emoji and other 4-byte characters are mangled and the hash won't match the text.

Our PHP, which you can copy as it is (PHP 7.4+; from `ProviderEmbedder.php`):

```php
const FIELDS = ['about_title', 'about_description', 'services_offered_title', 'services_offered_description', 'relevant_experience'];

/** $services: [['title' => ..., 'description' => ...], ...]; $achievements: [string, ...]; both in display order. */
function fieldsFromShowcase(?string $headline, ?string $bioHtml, array $services, array $achievements): array
{
    $nonEmpty = static fn (array $values): array => array_values(array_filter($values, static fn (string $v): bool => $v !== ''));

    return [
        'about_title'                  => trim((string) $headline),
        'about_description'            => plainText($bioHtml),
        'services_offered_title'       => implode('; ', $nonEmpty(array_map(static fn (array $s): string => trim((string) ($s['title'] ?? '')), $services))),
        'services_offered_description' => implode("\n", $nonEmpty(array_map(static fn (array $s): string => plainText($s['description'] ?? null), $services))),
        'relevant_experience'          => implode(' ', $nonEmpty(array_map(static fn (?string $a): string => str_replace("\n", ' ', plainText($a)), $achievements))),
    ];
}

function plainText(?string $html): string
{
    $html  = preg_replace(['#<li\b[^>]*>#i', '#<br\s*/?>|</(?:p|li)>#i'], ['- ', "\n"], (string) $html);
    $text  = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = array_map(static fn (string $line): string => trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line)), explode("\n", $text));

    return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== '' && $line !== '-'));
}

function providerText(array $fields): string
{
    $parts = array_map(static fn (string $f): string => (string) ($fields[$f] ?? ''), FIELDS);

    return implode(' ', array_filter($parts, static fn (string $p): bool => $p !== ''));
}

function textHash(array $fields): string
{
    return hash('sha256', providerText($fields));
}
```

Build the record as `fieldsFromShowcase(...) + ['years_experience' => ...]`.

**Check it:** `testing/check.py fixtures` (cases 9001-9006) and `check.py database` (D9-D11).

## G2. Embedding profiles

A profile can only be matched once it has a `showcase_embeddings` row. Embed in a **background job**, not in the request that saves the profile, so a slow or unavailable embedding-service never blocks your users.

**Which profiles need embedding:** published profiles with no row yet, or updated after their row (via the `showcases` table or view):

```sql
SELECT s.id FROM showcases s LEFT JOIN showcase_embeddings e ON e.showcase_id = s.id
WHERE s.status = 'published' AND (e.showcase_id IS NULL OR s.updated_at > e.updated_at)
ORDER BY s.id;
```

**One clock.** That query compares your profiles' `updated_at` with `showcase_embeddings.updated_at`, so both must come from the **same clock**. Many apps (Laravel, CodeIgniter) write `updated_at` from PHP, in the app's time zone (often UTC). Meanwhile the database's `NOW()` uses the database's time zone (for example Singapore, UTC+8). Mix the two and every embedding looks hours newer than any edit: **edits are silently ignored for hours**. We reproduced exactly this. So:
- If your app writes profiles' `updated_at` from PHP, pass the job's timestamps from PHP too (`date('Y-m-d H:i:s')` in the same time zone), as below.
- If your database fills `updated_at` itself (`ON UPDATE CURRENT_TIMESTAMP`), use `NOW()`.
- `check.py database` (D16) warns when the database clock isn't UTC. `check.py lifecycle` (L1) proves an edit really gets re-embedded.

**For each profile:**
1. Build its record, text and hash (G1).
2. If the text is empty, skip it. embedding-service refuses empty text (422), and such a profile can't be matched. It stays "waiting" in the query above, so don't let it trigger your job over and over (our job runs on a size or age threshold).
3. If the stored `text_hash` **and** `fields_json` are both unchanged, don't embed it again. Just set the row's `updated_at` to now (same clock) so it stops counting as waiting. If only `years_experience` changed, the record changed, so save it again.
4. Otherwise send the profiles to `POST /embed/providers` in batches of up to 128 (we use 32), with only the five text fields. Vectors come back in input order.
5. Upsert each row, with `:now` from your app's clock:

```sql
INSERT INTO showcase_embeddings (showcase_id, model, text_hash, fields_json, embedding, created_at, updated_at)
VALUES (?, ?, ?, ?, VEC_FromText(?), :now, :now)
ON DUPLICATE KEY UPDATE model = VALUES(model), text_hash = VALUES(text_hash), fields_json = VALUES(fields_json),
                        embedding = VALUES(embedding), updated_at = VALUES(updated_at);
```

The parameters, in order:
- `model`: the response's `model`;
- `fields_json`: the record from G1, JSON-encoded without escaping Unicode (`JSON_UNESCAPED_UNICODE`);
- `embedding`: the vector as a JSON array string, e.g. `[0.0123,-0.0456,...]`.

**How often:** we run the job every minute from cron. It embeds once 20 profiles are waiting, or once the oldest has waited 15 minutes, whichever comes first. It holds a lock so two runs never overlap. Any schedule works; the matcher picks up new rows within 30 seconds. If embedding-service is down, leave the profiles waiting and retry on the next run.

**First run:** embed every published profile (`data-setup.md`, step 5).

**If your timestamps were on the wrong clock:** fix the job, then run `UPDATE showcase_embeddings SET updated_at = '2000-01-01';` once and run the job. Every profile is re-checked: unchanged ones are only re-stamped, and missed edits are re-embedded.

**Never mix models.** Every row must carry the `model` that embedding-service reports. If the model ever changes, re-embed every profile.

**Check it:** `check.py database` (D7-D16) and `check.py lifecycle`.

## G3. Bump `updated_at` on every change that matters

The embedding job (G2) and the matcher only notice changes through your profiles' `updated_at` and `status` (via the `showcases` table or view).

- **Edits:** any change to the profile's text must bump the **profile's** `updated_at`. That includes edits to its services or achievements and its tags, even when they live in other tables. Frameworks don't do this by themselves: in Laravel, declare `protected $touches = ['profile'];` on the child models. In our test, editing only a service left the old text embedded.
- **Publish / unpublish:** set the status and bump `updated_at`.
- **Soft delete, suspension, bans:** whatever hides a profile on your site must make the `showcases` view report it as not `'published'`, and bump `updated_at` (`sql/README.md`, Step 1). Otherwise hidden profiles keep being recommended.
- **Hard delete:** delete the profile row. Its tags and embedding are removed by the foreign keys.
- **Every code path:** forms, APIs, admin tools, imports, bulk updates. A bulk `UPDATE ... SET is_published = 0` that doesn't touch `updated_at` is missed whenever the number of published profiles doesn't change.

Don't delete the embedding when unpublishing. If the profile is republished, the embedding is reused if its text hasn't changed.

**Check it:** `check.py lifecycle` (L1 edit, L2 unpublish, L3 republish), done through your app's own screens.

## G4. Storing tags

Gigs and profiles are tagged from the SkillsFuture taxonomy (`sf_*` tables): up to **2 categories**, **1-3 specialisations** under each, and up to **8 skills** per specialisation, each chosen from the skills linked to it in `sf_specialisation_skills`. These are the limits the model was trained with. Enforce them in your forms (our `Taxonomy::check()` does). Our picker (`app/Views/partials/taxonomy-picker.php`) shows one way to build the form.

- Store each chosen specialisation in `showcase_specialisations` / `gig_specialisations`.
- Store each chosen skill **under its specialisation** in `showcase_skills` / `gig_skills`. The foreign keys refuse a skill under a specialisation that the owner doesn't have, or that doesn't contain it. Offer only the skills linked to each specialisation.
- Categories are **not stored**: they are the parents of the specialisations. Make your form require at least one specialisation per ticked category.
- Use the `sf_*` ids.
- Saving a profile's tags must bump the profile's `updated_at` (G3).

The matcher reads profiles' tags straight from these tables. Gigs' tags travel in the `/match` request (G5).

**Check it:** `check.py database` (D6).

## G5. The `/match` request

```json
{"hire_id": 42, "hire_title": "Interim finance lead for a regional group",
 "hire_description": "Prepare transfer pricing documentation and support the year-end audit.",
 "industry": null,
 "taxonomy": [{"name": "Accountancy", "group": null, "specialisations": [
                {"name": "Assurance", "search_tag": "Assurance (Accountancy)", "skills": ["Auditing and Assurance Standards", "Financial Statements Analysis"]},
                {"name": "Tax", "search_tag": "Tax (Accountancy)", "skills": ["Tax Compliance", "Transfer Pricing"]}]}],
 "top_k": 10}
```

| Field | Value |
|---|---|
| `hire_id` | The gig's id (integer), stable for the gig's lifetime. The matcher saves the gig's vector under it in `gig_embeddings`, and reuses it the next time this gig is matched with the same title and description |
| `hire_title` | The gig title, trimmed |
| `hire_description` | The gig's main description as plain text, trimmed (below) |
| `industry` | `null`; the current model doesn't use it |
| `taxonomy` | The gig's tags (below); `[]` if it has none |
| `top_k` | How many results to show, 1-50; we use 10 |

**The description.** The model was trained on one description per gig. Our form asks for about 55-120 words describing the need and the work, then a sentence starting "Deliverable:" and a last one starting "Engagement duration:". Descriptions like that match best.
- If your description is rich text, convert it with `plainText()` (G1).
- If your gig spreads its text over several fields (description, requirements, deliverables), join the ones that describe the work into one plain-text description.
- Leave out notes about budget, logistics or contact details. Our additional-notes field is not sent.

**The taxonomy array** (our `Taxonomy::tags()`):
- One entry per category that has a chosen specialisation. `name` is `sf_categories.name`, and `group` is `sf_categories.group_name`.
- Under each category, its chosen specialisations: `name` is `sf_specialisations.name`, and `search_tag` is `"<specialisation> (<category>)"`.
- `skills` are the chosen `sf_skills.name` values for that specialisation, or `[]`.
- **Names must be exact.** Read them from the `sf_*` tables, never from user input. The matcher does not check them: a misspelt name is accepted but earns no tag credit.
- **Order doesn't matter**, and `group` and `search_tag` aren't used for ranking: the matcher compares names. We sort everything by name; send `group` and `search_tag` anyway.
- Some specialisation names appear under more than one category (e.g. "Project Management" under both Built Environment and Marine and Offshore). Keep each under its own category; fixture 9105 shows this.

**Check it:** `check.py fixtures` (cases 9101-9105).

## G6. Showing the results

Call `/match` **from your server**, never from the browser: the services are private and have no authentication. Use a **5-second timeout**; a match normally takes well under a second. Treat every outcome as a normal page state, never an error page; the gig is already saved.

| Outcome | Meaning | Show |
|---|---|---|
| 200, `results` not empty | Ranked profiles, best first | The results (below) |
| 200, `results` empty, `meta.catalogue_size` = 0 | Nothing is published and embedded yet | "No providers have published a profile yet" |
| 200, `results` empty, `meta.catalogue_size` > 0 | Nothing matched. The current model has no relevance cut-off, so this doesn't happen today, but handle it | "We couldn't find relevant experts" |
| 422 | The request is invalid (e.g. no title and description) | An explanation; no retry. Log it: it is a bug in G5 |
| 503, other status, timeout, connection error | A service is down, slow or busy | "Matching is unavailable, please try again", with a retry |
| 200 without a `results` list, or not JSON | Unexpected answer | Same as unavailable; log it |

**Showing results:**
- `provider_id` is your profile's id. **Load each profile from your database**, and skip any that is no longer published or no longer exists. The matcher's catalogue can lag your database by up to 30 seconds, so a profile hidden a moment ago can still come back once.
- `provider` only carries short hints from the embedded text; display your own data.
- Skip any result missing `rank`, `provider_id`, `score` or `provider`, rather than letting it break the page.
- `score` is 0-100 **within this gig** (rank 1 is always 100). Don't present it as a percentage match, and don't compare it across gigs.
- `signals.taxonomy.tag_level` can label why a result matched: 1 shared category, 2 shared specialisation, 3 shared specialisation and skill.

**Capacity.** The matcher processes one request at a time, about 10-14 per second in our tests; the rest queue. With a 5-second timeout, a burst of more than about 70 simultaneous match requests makes the last ones time out ("unavailable").
- Our page re-runs the match on every visit, so new profiles appear on refresh. A revisit reuses the gig's saved vector, so it skips embedding-service (`meta.gig_vector` is `stored`).
- If you expect more traffic, cache each gig's results for a few minutes. Or run more matcher processes (`--workers N`, or instances behind a load balancer): each holds the whole catalogue in memory, about 450 MB per process (`deployment-and-operations.md`).

`meta.timing_ms` and `meta.machine` say where the matcher's time went. You don't need them to show results; G8 saves them for monitoring.

## G7. Configuration in your app

Your app needs only the two service addresses and timeouts. Put them wherever your app keeps its settings (`.env`, YAML, a PHP config file):

| Setting | Our value | Notes |
|---|---|---|
| Embedding-service URL | `http://localhost:8001/` | Used by your G2 job |
| Embedding timeout | 30 s | Batches of 32 take a few seconds; allow for a cold start |
| Matcher URL | `http://localhost:8002/` | Used by G5/G6 |
| Matcher timeout | 5 s | |
| Results per gig (`top_k`) | 10 | 1-50 |

The services' own settings (database, device) are separate: see `engine.env.example` and `deployment-and-operations.md`.

If you build G9-G11, add the taxonomy-service address (ours: `http://localhost:8003/`), a timeout of about 25 s (a suggestion is an OpenAI call; keep it above `taxonomy.openaiTimeout` and under your PHP `max_execution_time`), and the service token (`taxonomy.serviceToken`), sent as the `X-Service-Token` header on every call. Our values are in `app/Config/Taxonomy.php`.

---

## Optional glue

## G8. Recording matches and the matcher dashboard

**What it gives you:** a record of every match your site runs (whether it worked, how long each stage took, and what it returned), and an admin page that summarises the latest runs. We use ours to watch latency and to see how often the top results share the gig's tags.

**Tables:** `sql/<variant>/04_run_records.sql` creates `analytics_outputs` (one row per match) and `run_outputs` (one row per result). Only your app writes and reads them.

**What to save, per `/match` call** (our `MatchRecorder::record()`, called from `Gigs::matches()`):

| Column(s) | From |
|---|---|
| `gig_id`, `user_id` | The gig, and its owner |
| `status`, `error` | `ok`, or `error` with the failure's message (any outcome in G6's "unavailable" rows) |
| `matcher_machine`, `candidate_pool`, `returned` | `meta.machine`, `meta.candidate_pool`, `meta.returned` |
| `matcher_vector_read_ms` … `matcher_total_ms` | `meta.timing_ms`: `vector_read`, `embed`, `candidates`, `score`, `response`, `total`. `embed` is null when the gig's stored vector was reused |
| `matcher_call_ms` | Your own timing of the HTTP call to `/match`. Minus `matcher_total_ms`, it is the network and queueing time |
| `save_*`, `db_*`, `php_views_ms`, `page_total_ms`, `action_*` | Your app's own timings of the request that saved the gig and of the matches page, if you measure them (we do, with a development-only request timer). Leave them NULL otherwise |
| `run_outputs`: one row per result | `rank`, `provider_id`, `score`, `raw_margin`, `provider.display_title` / `industry` / `bio_excerpt`, and each value of `signals.retrieval`, `signals.business_fit` and `signals.taxonomy` in the column of the same name |

**Rules:**
- **Never slow the page or break it.** Write the rows after the response has been sent (we use a PHP shutdown function; a queued job works too), and catch and log any failure. A recording problem must never turn a match into an error page.
- **Record failures too**, with `status = 'error'`. They are what the dashboard is for.
- A revisit of the matches page is a new run, with no save request (`action_total_ms` NULL).
- **Size:** about 11 rows per match with `top_k` 10. Decide how long to keep them, for example by deleting runs older than 90 days.

**The dashboard page** (ours: `/admin/dashboard`, `Controllers/Dashboard.php`, `Views/dashboard.php`) reads the latest 50 runs:
- typical (median) and slowest (95th percentile) time from Submit to matches, matching time and gig-embedding time;
- how many runs succeeded and failed;
- a paged table of runs, each with its top results and a bar of where its time went;
- how often results share a category, specialisation or skill with the gig (`tag_level`), for rank 1 and for all results.

**Put it behind your admin login.** Ours needs Shield's `admin.access` permission. It shows gig titles and profiles' details.

## Roles for G9-G11: your app decides who may do what

taxonomy-service has no idea of users or roles. It trusts whatever your server sends, and its token (`taxonomy.serviceToken`) only proves that the caller is your server, not which user is behind it. Every permission below is therefore **checked by your app** before it calls the service, with whatever roles your app already has (for example an admin or moderator role):

| Action | Who may do it | Calls |
|---|---|---|
| "Suggest tags with AI" on a gig or profile form | Any logged-in user who may edit that gig or profile | `POST /suggest`, `POST /proposals/attach` |
| See the review page and its list of terms | **Reviewers only** (admins or moderators) | `GET /proposals` |
| Approve or reject a term | **Reviewers only** | `POST /proposals/{id}/approve`, `.../reject` |
| Add, rename or remove taxonomy terms | **Reviewers only** | your own SQL, then `POST /refresh` |
| Read a notification, mark it read | **Only the user it is addressed to** | your own SQL on `user_notifications` (G11) |

Send the real user's id as `user_id` (G9) and the reviewer's id as `admin_user_id` (G10), so the records say who did what. Never take these ids from the request body: use your session.

**Our reference app is not a model for this.** In team31-app, `/taxonomy-dashboard` only requires a login (`app/Controllers/TaxonomyDashboard.php` says "no role is checked"); only our matcher dashboard (G8) checks a role (Shield's `admin.access` permission, in our `Config/Routes.php`). Apply a reviewer check to every review route in your app.

## G9. AI tag suggestion on the forms

**What it gives you:** a "Suggest tags with AI" button in the SkillsFuture picker. It reads what the user has typed so far and ticks the tags that fit. Terms the taxonomy doesn't have are shown as "pending review" and held for a reviewer (G10), rather than added straight away.

**Needs:** `taxonomy-service/` running (`deployment-and-operations.md`) with an OpenAI key, and `sql/<variant>/05_taxonomy_review.sql`.

**The flow** (`openapi/taxonomy-service.yaml` has every field):
1. **The user clicks the button.** Your page posts the form as filled in so far to **your server** (ours: `POST /tag-suggestions`, `TagSuggestions::suggest()`). Never call taxonomy-service from the browser: it holds your OpenAI key and can change your taxonomy.
2. **Your server calls `POST /suggest`** with `owner_type` (`gig` or `showcase`), `owner_id` null (the form isn't saved yet), `user_id` (the user), `title`, and `text`:
   - a gig: its description and notes, joined with a line break, with the gig title as `title`;
   - a profile: the same text the engine embeds (G1's `providerText()` of the form's headline, bio, services and achievements).

   Refuse an empty `text` yourself, with a message such as "Write the gig description first".
3. **Show the answer.** Tick each tag in `tags` (category, then its specialisations, then their skills) in your picker, within the form limits (G4). The service already keeps to them. For each entry in `pending`, show its name marked "pending review", and add its `id` to the form as a hidden field (ours: `proposals[]`).
4. **On save,** after the gig or profile has its id, call `POST /proposals/attach` with those ids, `owner_type`, `owner_id` and `user_id` (our `BaseController::attachSuggestedTerms()`). That links the pending terms to it, so an approval can tag it and a rejection can tell its owner. **Never fail the save** because this call failed: log it.
5. **On any error** (503, timeout, connection error): show "Suggestions are unavailable right now" and leave the picker as it is. The user picks tags by hand, as without G9.

**What users must know:** the button sends what they typed to OpenAI (`deployment-and-operations.md`, "Decisions and limits"). Say so near the button, or in your privacy notice.

**Pending terms are never tags.** They live only in `taxonomy_proposals` until a reviewer approves them, so the matcher never sees them. The ticked tags are saved through your normal G4 code.

## G10. Reviewing new terms

**What it gives you:** a page where a reviewer approves or rejects the terms G9 proposed. Approving adds the term to the taxonomy and tags it onto every gig and profile that was waiting for it. Rejecting removes it from them and records a message for each owner.

**The page** (ours: `/taxonomy-dashboard`, `Controllers/TaxonomyDashboard.php`, `Views/taxonomy-dashboard/proposals.php`):
- **List** with `GET /proposals?status=pending_review` (or `approved`, `rejected`). Each row has its term, kind (specialisation or skill), category and specialisation, the AI's confidence, and a duplicate flag (`auto_flag`: `exact`, `fuzzy`, `synonym` or `none`, with `closest_match_name`). Show the flag: it is the reviewer's main clue that the term already exists under another name.
- **Approve** with `POST /proposals/{id}/approve` and `admin_user_id`. Rows holding the same term are decided together. Show `skipped`: gigs or profiles that couldn't take the term because they were already at a form limit.
- **Reject** with `POST /proposals/{id}/reject`, `admin_user_id` and an optional `reason` (it goes into the owner's message).
- A **409** means the row was already decided (for example together with a duplicate): refresh the list rather than showing an error.
- **After approving,** clear any cache your app keeps of the taxonomy (ours: `Taxonomy::forgetTree()`), so your picker shows the new term.

**Restrict the page to reviewers.** Check your reviewer role on the page **and on every action it posts** (approve, reject, taxonomy edits), not only on the menu link (see "Roles for G9-G11" above). An ordinary user who reaches these actions can change your taxonomy and other users' tags.

**Rejections notify users.** Each rejection writes a message for the affected users. Showing those messages is G11.

**Editing the taxonomy elsewhere.** Our dashboard also lets a reviewer add, rename and remove taxonomy terms directly (`Views/taxonomy-dashboard/taxonomy.php`, `Libraries/Taxonomy.php`). If you change the `sf_*` tables outside taxonomy-service, call `POST /refresh` afterwards, so pending terms are re-checked against the new taxonomy. Renaming or removing a term changes or removes the tags that use it (`data-setup.md`, step 2).

**Approved terms reach the matcher late for profiles.** The matcher reloads profiles' tags only when a profile or its embedding changes (`updated_at`). So a profile that was tagged by an approval is matched with its new tag after its next edit or embedding, or after a matcher restart. To apply approvals at once, bump the affected profiles' `updated_at` (the approve response lists them in `attached`). Gigs aren't affected: their tags are sent with each match.

**Check it:** `testing/check.py taxonomy`, and `test-cases.csv` area **Taxonomy** (TC-G).

## G11. Showing users their notifications

**What it is:** when a reviewer rejects a term (G10), taxonomy-service writes one row to `user_notifications` for each gig or profile that had that term pending. The row tells the user that the term was removed from their gig or profile, why, and what to use instead. **The service only writes the rows. Your app must show them.** Without G11, users never learn why a tag they saw as "pending review" disappeared.

**Our app doesn't show them yet.** team31-app writes these rows but has no notification screen. So there is no reference code for this item; the table and the rules below are the whole contract.

**The table** (`sql/<variant>/05_taxonomy_review.sql`):

| Column | Holds |
|---|---|
| `user_id` | Who it is for: the user your app sent as `user_id` to `/suggest` or `/proposals/attach`; failing that, the gig's or profile's owner (`gigs.user_id` / `showcases.user_id`) |
| `type` | `taxonomy_proposal_rejected` (the only type today) |
| `message` | Ready to show, for example: `The specialisation "Crypto Custody" was not added to the taxonomy, so it has been removed from your gig #42. Reason: Too specific` (when the duplicate check found an existing term, it ends with `You can use "<term>" instead.`) |
| `data` | JSON with the details, if you want to build your own wording or a link: `proposal_id`, `owner_type` (`gig` / `showcase`), `owner_id`, `name`, `kind` (`skill` / `specialisation`), `reason` |
| `read_at` | NULL until your app marks it read |
| `created_at` | When it was rejected (UTC) |

**What to build:**
1. **Show unread messages** to the logged-in user, for example as a badge and a list in their account area or on the gig or profile page: `SELECT ... FROM user_notifications WHERE user_id = :me AND read_at IS NULL ORDER BY created_at DESC`.
2. **Mark them read** when shown or dismissed: `UPDATE user_notifications SET read_at = :now WHERE id = :id AND user_id = :me`.
3. **Only ever show a user their own rows**: always filter by the session's user id, never by an id from the request.
4. **Optionally, link** to the gig or profile in `data.owner_type` / `data.owner_id`, so the user can pick another tag there.
5. **Optionally, send an email** for each new row, with your existing mail system. The service sends nothing itself.

`message` is plain text written from the term and the reviewer's reason: escape it like any user content when you show it.

**Check it:** `test-cases.csv` TC-G10 and TC-G13.
