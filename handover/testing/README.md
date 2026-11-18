# Testing the integration

These checks tell you whether **your integration** of the recommendation engine is correct: your tables, the data your app writes, the requests it sends, and how it handles every answer. They are black-box (HTTP and SQL only), so they work however your app is written.

| Part | What it proves | How |
|---|---|---|
| `check.py services` | Both services run and behave as `../openapi/*.yaml` says | Automated, read-only (it saves one gig vector if `CHECK_GIG_ID` is set) |
| `check.py database` | Your tables are right, and the rows your app wrote are what the engine expects | Automated, read-only |
| `check.py lifecycle` | Editing, unpublishing and republishing a profile **in your app** reach the engine | Interactive: it tells you what to do in your app and watches. Staging only |
| `check.py fixtures` | Your code builds the same profile text, hashes and match requests as ours | Automated comparison with `fixtures/` |
| `check.py taxonomy` | Only with the optional taxonomy-service: it runs, is protected by its token, sees your taxonomy, and its answers match `../openapi/taxonomy-service.yaml` | Automated, read-only; `--suggest` adds one real OpenAI call |
| `test-cases.csv` | Everything above, plus the checks in your own app (pages, errors, latency). IDs start with `TC-`; its "Automated by" column names the `check.py` checks (E, M, D, L, T numbers) that cover a case | Manual checklist for QA |

Run everything against a **staging copy** of your database, never production. `../integration-steps.md` says which checks must pass at each step. Early on, some fail simply because a later step isn't done yet.

## Setup

```bash
cd handover/testing
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt    # Python 3.8 or later
.venv/bin/python check.py --env-file ../../engine.env services
```

`--env-file` reads the engine's own `engine.env`: the database settings and the embedding-service address. Add `MATCHER_URL` and `CHECK_GIG_ID` to it if they differ from the defaults. Environment variables (`EMBEDDING_URL`, `MATCHER_URL`, `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `CHECK_GIG_ID`, `TAXONOMY_URL`, `TAXONOMY_TOKEN`) win over the file. Use them to point the checks at another address, or at a read-only database user.

Each run prints `PASS`, `WARN` or `FAIL` per check, with the reason for anything that isn't a pass, and exits non-zero if anything failed. A `WARN` is allowed but should be read. For example: "3 published profiles have no embedding yet".

## The checks

**`services`:** the embedding service's vectors (1024-d, unit length, stable, input order kept, batch limits, empty text refused) and the matcher's contract:
- responses match the spec;
- ranks, scores and `catalogue_size` are consistent;
- `top_k` limits are enforced, and invalid requests get 422;
- parallel requests queue and all succeed;
- the gig vector is saved after a match (M14), and a second match of the same gig reuses it instead of calling embedding-service (M15). Both need `CHECK_GIG_ID`, and are skipped while the catalogue is empty, because with nothing to rank the matcher doesn't embed the gig.

**`database`:**
- MariaDB version, tables, and matching id types (`../sql/README.md`);
- the taxonomy is loaded, and tags are within the form limits;
- every stored vector comes from the model the service runs;
- every `fields_json` is complete;
- every `text_hash` equals sha256 of the text built from `fields_json`;
- every published profile is embedded (D12), and none is left waiting after an edit (D15);
- a sample of stored vectors matches a fresh embedding of their `fields_json` (D13). This catches vectors stored from the wrong side (gig instead of profile), the wrong model, or the wrong text;
- the database clock (D16). It warns when the database's time zone isn't UTC, because then `updated_at` written by your app and by the database's `NOW()` can disagree (`../integration-guide.md`, G2 "One clock").

**`lifecycle --showcase-id N`:** pick a published, embedded profile on staging. The check then asks you, one step at a time, to:
1. edit its bio in your app and let your embedding job run. It checks the stored `text_hash` changes (L1, waiting up to `--edit-wait` seconds, default 300);
2. unpublish it in your app. It checks the matcher drops it within 30 s (L2);
3. publish it again. It checks it comes back (L3).

Because you act through your own screens, this tests your real G2 and G3 code. It catches the problems that are otherwise silent: an edit that doesn't bump `updated_at`, a job on the wrong clock, an unpublish the `showcases` view doesn't see. `--auto` does steps 2-3 by writing `showcases.status` directly, for setups where that is a real column; it can't with the adapter views.

**`taxonomy [--suggest]`** (optional service only; run with `--env-file ../../taxonomy.env`, which gives it the service's token and database):
- the service is up, has an OpenAI key (T2), and refuses calls without its token (T3);
- its taxonomy is the one in your `sf_*` tables (T4), and `/lookup` finds names in any case, with a typo, and not at all (T5-T7);
- the review queue's rows match the spec (T8), and the tables and `user_id` columns it needs exist (T9-T10);
- `--suggest` makes one real suggestion for a sample gig and checks it: within the form limits, and every tag exists under its parent in your taxonomy (T11-T15). It may add a pending proposal to your review queue; reject it there.

`taxonomy-service/check_service.py` is the service's own test suite. It tests every endpoint on a throwaway SQLite database with OpenAI faked, so it needs neither your database nor a key. Run it after installing.

**`fixtures OUTPUT.json`:** see the next section.

## Fixtures: proving your code matches ours

`fixtures/showcases.json` and `fixtures/gigs.json` hold sample inputs, each with the exact output team31-app's code produces for it. They cover rich text, lists, entities, empty fields, non-English text, whitespace, untagged gigs, shared specialisation names and the maximum number of skills. The expected values were generated by our real code (`fixtures/MakeFixturesTest.php`), not written by hand. Every gig's tags are real SkillsFuture combinations, so you can create those gigs in your database.

1. Copy `fixtures/fixtures_output.example.php` next to your code and fill in its three functions with calls into **your real code**:
   - `yourShowcaseRecord()`: the record you would store as `fields_json`;
   - `yourTextAndHash()`: the text you send to embedding-service and its hash;
   - `yourMatchRequest()`: the `/match` request for a gig with the fixture's tags.

   Not PHP? Produce the same JSON any way you like:
   ```json
   {"showcases": [{"id": 9001, "fields_json": {...}, "provider_text": "...", "text_hash": "..."}],
    "gigs":      [{"id": 9101, "match_request": {...}}]}
   ```
2. Run it: `php fixtures_output.php <path>/handover/testing/fixtures > fixtures_output.json`.
3. Run `.venv/bin/python check.py fixtures fixtures_output.json`. Every case must pass. A failure names the exact field and shows both values, for example:
   ```
   FAIL showcases 9001 (typical profile with rich text)
        fields_json.services_offered_title: expected "Financial planning and analysis; Fundraising support",
                                            got "Financial planning and analysis, Fundraising support"
   ```

Field names in `input` are our form's. Map them to your own fields: `headline` is the profile's one-line title (plain text), `about` is the bio (rich text), and so on; `../integration-guide.md` describes each field. If your profiles have no equivalent for a field, feed the fixture's value through the same path your nearest field uses. The point is to prove the text rules, not your form.

What is compared:
- **Profiles:** every field of the record, the text and the hash, exactly.
- **Match requests:** `hire_id`, `hire_title`, `hire_description` and `industry` exactly. The taxonomy is compared as the matcher uses it, by names only: order, `group` and `search_tag` don't affect ranking. `top_k` is your choice, so it is ignored.

Rankings are **not** compared with ours. Your catalogue is different, so the order will be too. `services` and `test-cases.csv` check that rankings behave correctly instead.

## Engine parity (`matcher/check_parity.py`): not part of acceptance

`matcher/check_parity.py` answers a different question: does our copy of the engine (`matcher/pipeline/`, `embedding-service/`) still compute exactly what the Data team's ranker computes? It compares against the ranker repository's training data and saved results, so it needs a checkout of that repository (see `../ranker.md`).

You don't need it to integrate, and it doesn't look at your database or code. Run it only if you change the engine's Python code or its packages, or upgrade to a newer ranker.

## Regenerating the fixtures (team31-app maintainers)

After changing how team31-app builds profile text, gig requests or tags, run this from the team31-app root:

```bash
php vendor/bin/phpunit handover/testing/fixtures/MakeFixturesTest.php
```
