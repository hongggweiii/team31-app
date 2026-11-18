# Integration, step by step

Follow these steps in order on a **staging** copy of your site and database. Each step says what to do, how to check it, and when it is done. The details live in the other documents; each step links to them.

`check.py` (in `testing/`) checks your work as you go. Early on, some of its checks are **expected** to fail because later steps haven't been done yet. Each step lists which checks must pass by then.

Paths below are relative to the unzipped `senseigigz-engine/` folder.

---

## Step 0. Check the prerequisites

| Need | Check | If not |
|---|---|---|
| **MariaDB 11.7 or later** for the database that holds your profiles and gigs (we run 11.8) | `SELECT VERSION();` | Stop here and plan an upgrade. MySQL and older MariaDB have no `VECTOR` type, which the engine needs. Check that your hosting provider offers 11.7+ |
| **Python 3.12 or later** on the server that will run the services (the matcher's XGBoost needs it) | `python3 --version` | Install it with your OS packages, `pyenv` or `uv`, or run the services in containers. Ubuntu 22.04 ships 3.10, which is too old |
| **About 4 GB of free memory** for the two engine services together | — | The embedding model takes about 1.3 GB in embedding-service. The matcher also loads it briefly when it builds its skill cache (first start, and after taxonomy changes) |
| **Internet access on first start** (downloads the embedding model from Hugging Face, several hundred MB) | — | Pre-download it (`deployment-and-operations.md`, "Offline servers") |
| **A staging copy** of your database and site | — | Don't integrate on production |
| Only for the optional AI tag suggestion (G9-G11): an **OpenAI API key**, and agreement that form text may be sent to OpenAI | — | Skip G9-G11; matching doesn't need them |

**Done when:** the first five are true.

## Step 1. Read the overview

Read `handover/README.md` (what you receive and what you build), then skim `handover/integration-guide.md` (the code you will write: items G1-G7, and the optional G8-G10). `reference/` holds the team31-app PHP the guide cites. It is CodeIgniter 4 code to read, not to run.

## Step 2. Create the tables

Follow `handover/sql/README.md`:

1. Find your profile and gig tables' names and id type (Step 1 there).
2. If they aren't named `showcases` and `gigs` with a `status` column, create the two **adapter views** described there. That takes a few lines of SQL, and the engine needs no changes.
3. Run `01_taxonomy.sql`, then `02` and `03` from the variant that matches your id type, with `REFERENCES` pointing at your real tables.
4. Only if you will build the optional glue: `04_run_records.sql` (G8) and `05_taxonomy_review.sql` (G9-G11). You can also run them later, at their own steps.

Set up the checks once:

```bash
cd handover/testing
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt     # Python 3.8+ is enough here
cp ../engine.env.example ../../engine.env                              # fill in the database settings now
.venv/bin/python check.py --env-file ../../engine.env database
```

**Done when:** D1-D4 pass. Expected at this step: D5 fails (no taxonomy yet), and the run stops at D7 (nothing embedded yet).

## Step 3. Load the SkillsFuture taxonomy

**PENDING:** the seed isn't in this package yet. See `handover/data-setup.md`, step 2, and ask team31 for it.

**Done when:** `check.py database` passes D5.

## Step 4. Install and start the two engine services

Follow `handover/deployment-and-operations.md`, "Install", "Configure" and "Run". Finish filling in `engine.env` first.

```bash
curl -s localhost:8001/health      # {"status":"ok", ..., "device": ...}
curl -s localhost:8002/health      # {"status":"ok", "providers": 0, ...}
cd handover/testing && .venv/bin/python check.py --env-file ../../engine.env services
```

taxonomy-service is optional: start it at step 9b if you want it.

**Done when:** both `/health` calls answer and `check.py services` has no FAIL. Expected at this step: `M2` warns that the catalogue is empty, and `M14` is skipped.

## Step 5. Tag gigs and profiles with SkillsFuture (G4)

Add a SkillsFuture picker to your gig and profile forms, and save the selections as described in `integration-guide.md`, G4. Our picker is `reference/app/Views/partials/taxonomy-picker.php` (HTML and JavaScript you can adapt). If you have existing tags, plan their mapping now (`data-setup.md`, step 3).

**Done when:** you can save a profile and a gig with tags, the rows appear in `showcase_*` / `gig_*` tables, and `check.py database` passes D6.

## Step 6. Build the profile text (G1)

Write the code that turns one of your profiles into the five fields, the text and the hash (`integration-guide.md`, G1). Then prove it matches ours:

1. Copy `handover/testing/fixtures/fixtures_output.example.php` next to your code as `fixtures_output.php`, and fill in `yourShowcaseRecord()` and `yourTextAndHash()` with calls into your code. Leave `yourMatchRequest()` for step 8: until then, make it return `[]`.
2. Run `php fixtures_output.php <path>/handover/testing/fixtures > fixtures_output.json`.
3. Run `.venv/bin/python check.py fixtures fixtures_output.json`.

**Done when:** every `showcases 900x` case passes. The gig cases fail until step 8.

## Step 7. Embed profiles and keep them current (G2, G3)

1. Write the embedding job (`integration-guide.md`, G2). Use **the same clock** for `showcase_embeddings.updated_at` as your app uses for profiles' `updated_at`.
2. Make every change to a profile bump its `updated_at`: its text, its services or achievements, its tags, publishing, unpublishing and deleting (G3).
3. Run the job once over all published profiles (the first full run: `data-setup.md`, step 5). Then schedule it, every minute for example.
4. Check:

```bash
.venv/bin/python check.py --env-file ../../engine.env database
.venv/bin/python check.py --env-file ../../engine.env lifecycle --showcase-id <a published profile id>
```

The lifecycle check asks you to edit, unpublish and republish that profile **in your app**, and watches the engine react.

**Done when:** `database` has no FAIL (read any WARN, especially D12, D15 and D16), and `lifecycle` passes L1-L3. `curl localhost:8002/health` shows `providers` equal to your published profiles.

## Step 8. Build the match request (G5)

Write the code that turns one of your gigs into the `/match` request, including its taxonomy array (`integration-guide.md`, G5). Fill in the gig function of `fixtures_output.example.php` and run `check.py fixtures` again.

**Done when:** every case, showcases and gigs, passes.

## Step 9. Show the matches (G6, G7)

Build the matches page: call `/match` from your **server**, never from the browser. Show the results, and handle every outcome in G6's table. Put the service URLs and timeouts in your app's own configuration (G7).

**Done when:** you have worked through `handover/testing/test-cases.csv` areas **Matching** (TC-C1 to TC-C7) and **Errors** (TC-D1 to TC-D7) on staging, including stopping each service to see the "unavailable" message.

## Step 9a (optional). Record matches and add the matcher dashboard (G8)

1. Run `sql/<variant>/04_run_records.sql` (with your gig table in its `REFERENCES`).
2. After each matches page is sent, save the run and its results (`integration-guide.md`, G8). Never let it slow or break the page.
3. Build the admin page over the latest runs, behind your admin login.

**Done when:** opening a few matches pages adds one `analytics_outputs` row each (and `run_outputs` rows for their results). A run while the matcher is stopped is saved with `status = 'error'`. Then work through `test-cases.csv` area **Run records** (TC-F).

## Step 9b (optional). AI tag suggestion, term review and notifications (G9-G11)

1. Run `sql/<variant>/05_taxonomy_review.sql`. If you use the adapter views, add `user_id` to both (`sql/README.md`, Step 1).
2. Install, configure and start taxonomy-service (`deployment-and-operations.md`): its own database user, `taxonomy.env` with your OpenAI key, and a service token.
3. Check it:

```bash
cd taxonomy-service && .venv/bin/python check_service.py      # offline: no database, OpenAI faked
cd ../handover/testing && .venv/bin/python check.py --env-file ../../taxonomy.env taxonomy --suggest
```

4. Add the button to your gig and profile forms, and link the pending terms on save (G9).
5. Build the review page, and check your reviewer role on the page and on every action it posts (G10, and "Roles for G9-G11" in the guide). taxonomy-service doesn't check roles: your app must.
6. Show users the notifications a rejection leaves them, each user only their own (G11).

**Done when:** `check.py taxonomy --suggest` has no FAIL, and you have worked through `test-cases.csv` area **Taxonomy** (TC-G). Reject any test terms `--suggest` left in the review queue.

## Step 10. QA and performance

Work through the rest of `test-cases.csv`, in particular TC-E1 (latency over 100 gigs) and TC-E2 (the services are not reachable from outside). Then run all four checks once more:

```bash
.venv/bin/python check.py --env-file ../../engine.env services
.venv/bin/python check.py --env-file ../../engine.env database
.venv/bin/python check.py --env-file ../../engine.env lifecycle --showcase-id <id>
.venv/bin/python check.py fixtures fixtures_output.json
.venv/bin/python check.py --env-file ../../taxonomy.env taxonomy      # only with taxonomy-service
```

**Done when:** no FAIL anywhere, every WARN understood, and the test cases signed off.

## Step 11. Go to production

1. Upgrade or confirm MariaDB 11.7+, then run steps 2-3 (tables, views, taxonomy) on production.
2. Deploy the services privately (step 4): process manager, restart on failure, health checks in your monitoring.
3. Deploy your app changes. Run the embedding job's first full run, then enable its schedule.
4. If you use them: run scripts 04 / 05 on production, deploy taxonomy-service privately with its token set, and make sure the review page is for reviewers only.
5. Run `check.py services` and `database` (and `taxonomy`, without `--suggest`) against production with a **read-only** database user. Skip `lifecycle` on production.

**Done when:** `/health` on the matcher shows all published profiles, and a test gig shows sensible matches.
