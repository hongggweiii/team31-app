# Integration guide: the code your app writes

You run our two Python services as they are (`embedding-service/` and `matcher/`). Your app only has to connect to them. That connecting code, the **glue**, is listed below. Every item is something team31-app does today; our version is in `reference/` (CodeIgniter 4, for reading).

The glue's mistakes don't raise errors. If your profile text is built slightly differently, or a profile edit is never re-embedded, everything keeps working, but matches get quietly worse. That is why each item has exact rules, and why `testing/check.py` checks your output against ours.

**Our words:** a *showcase* is a provider's profile, and a *gig* is a hirer's job post. The engine's tables and fields use these names; yours can differ (`sql/README.md`, Step 1).

| # | Glue | When it runs | Our reference (in `reference/app/`) |
|---|---|---|---|
| G1 | Turn a profile into the five text fields, its text and its hash | When embedding a profile | `Libraries/ProviderEmbedder.php` |
| G2 | Embed profiles and store the vectors | In a background job | `Commands/EmbedAuto.php`, `Commands/EmbedBatch.php`, `Libraries/EmbeddingStore.php` |
| G3 | Bump `updated_at` on every change that matters | Wherever your app changes profiles | — |
| G4 | Store the SkillsFuture tags chosen on your forms | When a gig or profile is saved | `Libraries/Taxonomy.php` (`check()`, `save()`), `Views/partials/taxonomy-picker.php` |
| G5 | Build the `/match` request for a gig, including its taxonomy array | When showing a gig's matches | `Libraries/MatcherClient.php` (`gigRequest()`), `Libraries/Taxonomy.php` (`tags()`) |
| G6 | Show the results, and handle every outcome without an error page | Same | `Libraries/MatcherClient.php` (`match()`), `Libraries/MatcherException.php`, `Controllers/Gigs.php` (`matches()`), `Views/gig-matches.php` |
| G7 | Configure the two service URLs and timeouts | Once | `Config/Embedding.php`, `Config/Matcher.php` |

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

Gigs and profiles are tagged from the SkillsFuture taxonomy (`sf_*` tables): up to **2 categories**, **1-3 specialisations** under each, and up to **8 skills** per specialisation, each chosen from the skills linked to it in `sf_specialisation_skills`. These are the limits the model was trained with. Enforce them in your forms (our `Taxonomy::check()` does). Our picker (`reference/app/Views/partials/taxonomy-picker.php`) shows one way to build the form.

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
| `hire_id` | The gig's id (integer). The matcher saves the gig's vector under it in `gig_embeddings` |
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

**Capacity.** The matcher processes one request at a time, about 14 per second in our tests; the rest queue. With a 5-second timeout, a burst of more than about 70 simultaneous match requests makes the last ones time out ("unavailable").
- Our page re-runs the match on every visit, so new profiles appear on refresh.
- If you expect more traffic, cache each gig's results for a few minutes. Or run a second matcher instance behind a load balancer: each instance holds the whole catalogue in memory.

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
