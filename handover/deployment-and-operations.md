# Deployment and operations

## What runs where

```
your PHP app ──HTTP──▶ matcher (8002) ──HTTP──▶ embedding-service (8001)
     │                      │
     │ SQL                  │ SQL (reads showcases, tags, embeddings; reads and writes gig_embeddings)
     ▼                      ▼
                MariaDB 11.7+ (your database)
your embedding job ──HTTP──▶ embedding-service (8001), then writes showcase_embeddings

optional:
your PHP app ──HTTP──▶ taxonomy-service (8003) ──HTTPS──▶ OpenAI
                            │ SQL (reads and adds to sf_*, tags gigs / showcases, taxonomy_proposals, user_notifications)
```

| Service | Folder | Does | Needs |
|---|---|---|---|
| embedding-service | `embedding-service/` | Turns text into 1024-d vectors with `mixedbread-ai/mxbai-embed-large-v1` | Python 3.10+; about 1.3 GB of memory for the model; CPU works, a GPU is faster; the model files (downloaded on first start, several hundred MB) |
| matcher | `matcher/` | Ranks profiles for a gig with the ranker's model | **Python 3.12+** (its XGBoost needs it); database access; the same model files (loaded briefly to build its skill cache); a writable `matcher/cache/` (~19 MB per taxonomy version) |
| taxonomy-service (**optional**) | `taxonomy-service/` | AI tag suggestion on your forms, and the review of terms the taxonomy doesn't have (`integration-guide.md`, G9-G11) | Python 3.10+; database access; an **OpenAI API key** and outbound HTTPS to OpenAI; little memory (no model of its own) |

We run all three on Python 3.13. They are plain FastAPI apps served by uvicorn. Keep the service folders and `engine.env` together as the package unzips them; the commands below assume that layout.

**Matching works without taxonomy-service.** It only helps users pick SkillsFuture tags and lets you grow the taxonomy. Skip it, and everything about it below, if you don't want AI suggestions or don't want to send form text to OpenAI.

## Install

```bash
cd embedding-service && python3.12 -m venv .venv && .venv/bin/pip install -r requirements.lock.txt
cd ../matcher        && python3.12 -m venv .venv && .venv/bin/pip install -r requirements.lock.txt
cd ../taxonomy-service && python3.12 -m venv .venv && .venv/bin/pip install -r requirements.lock.txt   # optional
```

taxonomy-service has its own test suite, which needs no database and no OpenAI key (it uses a throwaway SQLite database and fakes OpenAI): `cd taxonomy-service && .venv/bin/python check_service.py`. It should end with `All checks passed`.

Use `python3.12` or later, whatever your system calls it. Install from **`requirements.lock.txt`**: those are the exact versions we tested. `requirements.txt` only sets minimums. A fresh install from it on the day we wrote this already pulled a newer torch than ours, and newer libraries can shift vectors slightly. If you upgrade packages later, re-run `matcher/check_parity.py` (`ranker.md`) and `testing/check.py`.

**Offline servers:** both services load the model from the Hugging Face cache (`~/.cache/huggingface`, or `$HF_HOME`). On a server without internet, fill that cache on a machine that has internet, then copy it over:

```bash
.venv/bin/python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('mixedbread-ai/mxbai-embed-large-v1')"
```

## Configure

Copy `handover/engine.env.example` to `engine.env` next to the two service folders, fill it in, and keep it out of git. It holds:
- the matcher's database connection;
- the embedding-service address;
- optionally `EMBED_DEVICE`, and `MATCHER_MACHINE` (a label for the server, reported in each match's `meta.machine`).

`testing/check.py` reads the same file.

Give the matcher its own database user with only what it needs:
- `SELECT` on `showcases` (your table or view), the `sf_*` tables, the `showcase_*` tag tables and `showcase_embeddings`;
- `SELECT, INSERT, UPDATE` on `gig_embeddings`.

**taxonomy-service** reads the same setting names (`database.default.*`), so give it **its own file**: copy `handover/taxonomy.env.example` to `taxonomy.env`, next to `engine.env`. That keeps the OpenAI key and its broader database rights away from the matcher. Its database user needs:
- `SELECT` on the `sf_*` tables, and `INSERT` on `sf_specialisations`, `sf_skills` and `sf_specialisation_skills` (approving a term adds it);
- `SELECT, INSERT` on the four tag tables (`gig_specialisations`, `gig_skills`, `showcase_specialisations`, `showcase_skills`): an approved term is tagged onto the gigs and profiles that were waiting for it;
- `SELECT` on `gigs` and `showcases` (`id` and `user_id`: see `sql/README.md`, script 05);
- `SELECT, INSERT, UPDATE` on `taxonomy_proposals`, and `INSERT` on `user_notifications`.

The services read the file through uvicorn's `--env-file` option (it uses `python-dotenv`, which the requirements include). It handles the dotted names, spaces and special characters in values, quotes and comments. Docker's `--env-file` reads it as well, but takes values literally: don't quote them there.

Don't load it with a shell `export` or `env $(cat engine.env)`. Shells refuse names with dots, and splitting breaks passwords that contain spaces.

## Run

Start embedding-service first, then the matcher:

```bash
cd embedding-service && .venv/bin/uvicorn app:app --env-file ../engine.env --host 127.0.0.1 --port 8001
cd matcher           && .venv/bin/uvicorn app:app --env-file ../engine.env --host 127.0.0.1 --port 8002
cd taxonomy-service  && .venv/bin/uvicorn app:app --env-file ../taxonomy.env --host 127.0.0.1 --port 8003   # optional, any order
```

Always start taxonomy-service with `--env-file`. Without it, it falls back to reading `../.env` (team31-app's own layout), which in your package doesn't exist, and it can't reach the database.

A systemd unit for the matcher, for example (the embedding-service one is the same with its folder and port):

```ini
[Service]
WorkingDirectory=/opt/senseigigz-engine/matcher
ExecStart=/opt/senseigigz-engine/matcher/.venv/bin/uvicorn app:app --env-file ../engine.env --host 127.0.0.1 --port 8002
Restart=on-failure
User=senseigigz
```

- **One worker each.** Don't add `--workers`. The matcher processes one request at a time and queues the rest; every extra worker would load its own copy of the catalogue and model.
- **Bind to a private address** (`127.0.0.1` or your private network). embedding-service and the matcher have no authentication: never expose them to the internet. taxonomy-service can require a shared secret (`taxonomy.serviceToken`, sent as the `X-Service-Token` header). Set one, because its endpoints change your taxonomy; keep it private all the same.
- **Keep them running** with your process manager, with restart on failure.
- **Startup order:** the matcher starts even if embedding-service isn't up yet, or if no showcase is embedded yet. Matching then fails (503) or returns an empty list until those are ready.
- **The skill-vector cache:** on first start, and whenever the taxonomy's skill list changes, the matcher also loads the embedding model once to build its skill-vector cache. That start takes longer.

Health checks for your monitoring:

```bash
curl -s localhost:8001/health   # {"status":"ok","model":...,"dimensions":1024,"device":"cpu"}
curl -s localhost:8002/health   # {"status":"ok","providers":<showcases being matched>,"ranker_version":...}
curl -s localhost:8003/health   # {"status":"ok","model":"gpt-4o-mini","openai_key":true,"categories":38,...,"pending":<terms to review>}
```

(With `taxonomy.serviceToken` set, add `-H "X-Service-Token: <token>"`.)

## Logs

Both log to stdout and stderr (uvicorn). Lines worth watching:

| Line | Meaning |
|---|---|
| `matching against N providers` | The matcher started with N showcases |
| `catalogue reloaded: N showcases` | It picked up changes (checked every 30 s) |
| `catalogue refresh failed; still serving the previous one` | The database was unreachable during a reload; it retries in 30 s |
| `embedding-service warm-up failed` | The matcher couldn't reach embedding-service at startup or during its 2-minute keep-warm call |
| `[timing] matched gig N in X ms: read stored vector (hit\|miss) ... embed ... candidates ... score ...` | Each match, broken down. `hit`: the gig's saved vector was reused and embedding-service skipped (`embed skipped`) |
| `reading the stored embedding of gig N failed` | The stored-vector lookup failed (e.g. the database blipped). The gig was embedded live instead; matching still succeeded |
| `[timing] embedded N gig(s) in X ms` | embedding-service doing the gig half of a match |
| `saving the embedding of gig N failed` | The gig vector couldn't be saved (e.g. the gig id doesn't exist). Matching itself still succeeded |
| taxonomy-service: `taxonomy: N categories, N specialisations, N skills; model ...` | It started and loaded the taxonomy |
| taxonomy-service: `tag suggestion failed: ...` | OpenAI failed, timed out or no key is set. The user got "Suggestions are unavailable" and picks tags by hand |
| taxonomy-service: `synonym check skipped: ...` | OpenAI was unavailable for the duplicate check of a new term; only the spelling checks ran |

## Performance

From our load tests, with the database in the US and the app in Singapore:
- A **single match** takes about 0.1-0.5 s inside the matcher with about 3,000 showcases: gig embedding plus ranking.
- The **whole "post a gig → see matches" flow** had a median of 3.3 s over 1,000 sequential runs (p95 3.7 s, all successful). About 70% of that was database round trips across the Pacific (~260 ms each), not the engine.
- **Revisits are cheaper.** The matcher reuses a gig's saved vector when the same gig is matched again with unchanged text, which skips embedding-service (`meta.gig_vector` is `stored`). The lookup itself is one database query, so a gig's **first** match costs one extra round trip to your database. With a database close to the services that is a few milliseconds.
- A **database close to your app**, and **persistent connections**, are what bring the flow down. The engine itself is rarely the bottleneck.
- **Capacity:** the matcher handles one request at a time: about 10-14 matches per second. In our step test, the median flow stayed at about 3.5 s with up to 50 users at once. At 100 users at once it rose to 6.6 s, because requests queue in front of the matcher (its own work stayed at about 75 ms each). A burst of more than about 70 simultaneous requests exceeds a 5-second timeout for the last ones (`integration-guide.md`, G6, "Capacity").
- **taxonomy-service** (optional) answers a suggestion in a few seconds: almost all of it is the OpenAI call, capped at `taxonomy.openaiTimeout` (20 s). Each click of "Suggest tags with AI" is one OpenAI call that carries the whole taxonomy, so watch your OpenAI usage.
- **Catalogue size:** we ran with about 3,000 profiles. The matcher keeps the whole catalogue in memory (about 2 KB per profile for the vector, plus its text for BM25). It reloads it in full whenever profiles or embeddings change, at most every 30 seconds. With many times more profiles, watch the reload time in the log (`catalogue reloaded`) and the memory use.

## Runbook

| Symptom | Likely cause | What to do |
|---|---|---|
| Matches page says matching is unavailable | embedding-service or the matcher is down or slow | `curl` both `/health`. Restart the one that fails. Check the matcher log for `warm-up failed` |
| A new profile never appears in matches | Your embedding job isn't running, or the profile has no text | `testing/check.py database`: D12 lists profiles without embeddings. Check the job's schedule and log |
| An edited profile still matches on its old text | The edit didn't bump the profile's `updated_at` (e.g. only a service changed), or the job's timestamps are on another clock | `check.py database` (D15, D16) and `check.py lifecycle`. See `integration-guide.md`, G2 "One clock" and G3. If the clocks were wrong, follow G2's repair step |
| An unpublished or deleted profile still appears | The `showcases` view still reports it as `'published'`, or `updated_at` wasn't bumped, or the matcher can't reach the database | Check the view's conditions (`sql/README.md`, Step 1) and G3. Look for `catalogue refresh failed`. For up to 30 s after a change this is expected; your page filters such results out (G6) |
| Every match returns an empty list | Nothing is published and embedded (`/health` providers = 0) | Run your embedding job (`data-setup.md`, step 5) |
| Matches got worse after a change on your side | Showcase text or tags are built differently from ours | `check.py fixtures` and `check.py database` (D11, D13) |
| Matcher very slow to start | Building its skill-vector cache after a taxonomy change | Wait; it happens once per taxonomy version |
| `D8` fails (more than one model) | Some rows came from another embedding model | Re-embed those rows (or all of them) |
| A service won't start: `Error loading ASGI app` or `No module named ...` | Wrong folder, or not installed from the lock file | Start uvicorn from inside the service's folder, with its `.venv`; reinstall from `requirements.lock.txt` |
| Installing the matcher fails: pip `Could not find a version that satisfies the requirement xgboost==...` | Python older than 3.12 (XGBoost 3.4 needs 3.12+) | Recreate the matcher's venv with Python 3.12+ |
| The matcher won't start: `Access denied` or `Unknown database` | `engine.env` database settings | Check the `database.default.*` values, and that uvicorn is started with `--env-file ../engine.env` |
| "Suggestions are unavailable right now" on every click | taxonomy-service is down, has no OpenAI key, or OpenAI is failing or slow | `curl localhost:8003/health`: `openai_key` must be `true`. Look for `tag suggestion failed` in its log. Users can still pick tags by hand |
| taxonomy-service answers 401 | `taxonomy.serviceToken` is set but your app doesn't send it (or sends another) | Send the same value in the `X-Service-Token` header (`integration-guide.md`, G9) |
| A term approved on the review page doesn't help a profile's matches yet | The matcher reloads profiles' tags only when a profile or its embedding changes (`updated_at`). Approving a term adds tag rows without changing either | It is picked up at the next profile change. To apply it now, bump the affected profiles' `updated_at`, or restart the matcher |
| The suggestion's tag list is stale after taxonomy edits | taxonomy-service caches the taxonomy for `taxonomy.cacheSeconds` (a day) | Call `POST /refresh` after editing the `sf_*` tables outside the service (our review page does), or restart it |

## Changing or upgrading the engine

- **New ranker version from the Data team:** follow `ranker.md`. Copy the files, bump `RANKER_VERSION` and run `check_parity.py`.
- **New embedding model, text recipe or query prefix:** every stored vector is out of date. Update embedding-service, run the parity check, then re-embed every profile. The model name in `showcase_embeddings.model` tells old rows from new.
- **After any change:** run `testing/check.py services` and `database`.

## Decisions and limits worth knowing

- **Scores are relative.** `score` is 0-100 within one gig (rank 1 is always 100), not a probability or a match percentage.
- **No relevance cut-off.** The matcher always returns the best `top_k` of the catalogue, even when they are poor fits. Filtering out weak matches would need a threshold agreed with the Data team.
- **Three features are always zero.** `budget_fit`, `seniority_fit` and `avail_immediacy` (`signals.business_fit`) are zero because the model was trained with them zeroed. Budget, seniority and availability don't affect ranking today.
- **Only the gig's title and main description are used** (`integration-guide.md`, G5), as in the model's training data. So are only the profile's five text fields, its tags and its years of experience (G1).
- **One request at a time.** The matcher queues parallel requests; we measured about 10-14 matches per second. 100 parallel requests all succeeded, the last after about 7 s. To serve more, run more matcher processes (`--workers N`, or several instances behind a load balancer). Each one holds its own copy of the catalogue and model: plan about 450 MB of memory and one CPU core per process.
- **Gig vectors are reused by id.** A gig matched again with the same title and description reuses its vector from `gig_embeddings`. `hire_id` must therefore always be the gig's own, stable id: never send a temporary or reused id.
- **AI suggestions send form text to OpenAI** (taxonomy-service only): the gig's title, description and notes, or the profile's headline, bio, services and achievements. Check this against your privacy policy and data-protection obligations before enabling it.
- **Roles are yours to enforce.** taxonomy-service doesn't know who is calling it; its token only proves the call comes from your server. Your app must allow only reviewers to list, approve, reject or edit terms, and show each user only their own notifications (`integration-guide.md`, "Roles for G9-G11").
- **Notifications are written, not sent.** A rejection leaves `user_notifications` rows; the service sends no email or push. Your app shows them (`integration-guide.md`, G11).
- **Up to 30 seconds of lag.** The matcher's catalogue reflects your database within 30 seconds, so filter results against your own data before showing them (G6).
- **Vectors depend slightly on batching.** The same profile embedded alone or in a different batch can differ in the last decimal places (cosine ≈ 0.999). This is expected; at most it can swap near-tied showcases.
- **Taxonomy names must be exact.** The matcher doesn't validate them against the `sf_*` tables (`integration-guide.md`, G5).
- **Licences:** the embedding model `mxbai-embed-large-v1` is Apache-2.0. For the ranker's code, model and training data, check the terms with the Data team before redistributing.
