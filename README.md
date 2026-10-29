# bt4103-team31-app
NUS BT4103 Capstone Project — SenseiGigs

CodeIgniter 4 monolith serving both the API and the server-rendered frontend, backed by a MariaDB database hosted on Layerbase.

## Project structure

```
app/
  Config/       framework config (routes, database, paths, filters, ...)
  Controllers/  request handlers
  Models/       database access
  Views/        page templates (layout/, partials/, per-page files)
  Database/     migrations and seeders
public/         web docroot (only folder exposed to the browser)
embedding-service/  Python: turns showcases and gigs into vectors (port 8001)
matcher/        Python: live gig -> showcase matching with the ranker's pipeline (port 8002)
writable/       runtime output (logs, cache, sessions)
tests/          PHPUnit tests
spark           CI4 CLI tool
.env            local config + secrets (gitignored)
.env.example    template for teammates
```

## Prerequisites

- PHP 8.2+ with `intl`, `mbstring`, `mysqlnd` extensions
- Composer

## First-time setup

```bash
composer install
cp .env.example .env      # then fill in Layerbase credentials
php spark key:generate    # writes encryption.key into .env
php spark serve           # http://localhost:8080
```

## Verify the DB connection

Once the Layerbase host/user/password/database are filled into `.env`:

```bash
php spark db:table --show
```

Lists the tables in the connected database. If credentials are wrong, the MySQLi error points at the failing field.

## Embeddings (`embedding-service/`)

Showcases and gigs are turned into vectors for matching by a small Python service, because the embedding model can't run in PHP. It copies the ranker's code unchanged (`provider_text` and `hirer_text` from `ranker/pipeline/corpus.py`, plus the encode and normalise steps and the query prefix from `retrieval_dense.py`), so the web app produces the same vectors the ranker was built on. `matcher/check_parity.py` confirms they are bit-identical.

- Model: `mixedbread-ai/mxbai-embed-large-v1`. Showcases are the document side (no prefix, `POST /embed/providers`); gigs are the query side, prefixed with `Represent this sentence for searching relevant passages: ` (`POST /embed/gigs`)
- Output: one vector per showcase, 1024 dimensions, unit length (use cosine or inner-product distance)
- Embedded text: the ranker's five provider fields, joined with spaces in this order, empty ones skipped. `ProviderEmbedder::fieldsFromShowcase()` fills them from the create-profile form's data, with rich text reduced to plain text:

| Ranker field | From the showcase |
|---|---|
| `about_title` | Headline |
| `about_description` | Bio (`about`) |
| `services_offered_title` | Every service title, `; `-separated |
| `services_offered_description` | Every service description |
| `relevant_experience` | Relevant achievements, joined into one paragraph (the ranker data has no bullets or line breaks) |

Nothing else is embedded: not Title, Credentials (which the ranker also leaves out), Availability, Rate, How I work, Technical proficiency, categories or documents.

The stored record (`fields_json`) also carries the showcase's `years_experience`, which is not embedded: `matcher/` reads it for the ranker's `exp_years` feature. `embed:batch` re-saves a showcase whenever its record changes, even if the embedded text didn't.

A gig is embedded from the ranker's three gig fields, in the same way, live by `matcher/` when it is matched (see below). They come from the gig:

| Ranker field | From the gig |
|---|---|
| `hire_title` | Gig Title |
| `hire_description` | Short Gig Description (`summary`) |
| `hire_description_additional_notes` | empty (the form has no notes; nor do the ranker's training gigs) |

Run it alongside `php spark serve` (Python 3.10+):

```bash
cd embedding-service
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
uvicorn app:app --port 8001   # the first run downloads the model (several hundred MB)
```

From PHP, `App\Libraries\ProviderEmbedder` calls it. The service URL is `embedding.baseURL` in `.env`.

```php
$result = (new \App\Libraries\ProviderEmbedder())->embedShowcase($showcaseId);
// ['showcase_id' => 12, 'model' => 'mixedbread-ai/mxbai-embed-large-v1', 'dimensions' => 1024, 'embedding' => [1024 floats]]
```

`embedShowcase()` reads the saved showcase, its services and its achievements from the database. It throws a `RuntimeException` if the showcase doesn't exist, the service is down, all five fields are empty, or the vector isn't 1024 values long. It doesn't save anything.

To check the service end to end against a saved showcase (read-only; prints the model, dimensions, vector length and first values):

```bash
php spark embed:showcase <showcase_id>
```

### Storing and searching vectors (MariaDB Vector)

Vectors live in the app database itself. MariaDB 11.7+ (Layerbase runs 11.8) has a `VECTOR(n)` column type and a `VECTOR INDEX`, an HNSW graph for approximate nearest-neighbour search, so no separate vector database is needed.

- `showcase_embeddings` and `gig_embeddings`: one row per showcase / gig with `model`, `text_hash` (sha256 of the embedded text), `fields_json` (the ranker-format record that was embedded, which `matcher/` reads) and `embedding VECTOR(1024)`, under a `VECTOR INDEX ... DISTANCE=cosine`. They're separate tables because MariaDB allows one vector index per table, and gigs are embedded differently (query side). Rows cascade-delete with their showcase / gig.
- Showcases are embedded offline and batched; nothing is embedded when a showcase is saved. Run on a schedule (needs `embedding-service/` up):

  ```bash
  php spark embed:batch showcases [--size 32] [--all]   # options take a space, not "="
  ```

  It pages through published showcases, skips any whose embedded text and stored record (`fields_json`) are unchanged, sends each page to `POST /embed/providers` in one request and upserts the vectors. Re-running is cheap. `--all` re-embeds everything (e.g. after a model change); `--ids 5,6,7` handles only those showcases.

  A new or edited showcase can't be matched until this has run, so let cron run `embed:auto` every minute (`crontab -e`; use absolute paths, cron has a minimal `PATH`):

  ```cron
  * * * * * cd /path/to/team31-app && /opt/homebrew/bin/php spark embed:auto >> writable/logs/embed-auto.log 2>&1
  ```

  `embed:auto` counts the published showcases waiting to be embedded (no vector yet, or updated since it) and embeds just those (as `embed:batch` does, without rescanning the rest) once **20** are waiting or the oldest has waited **15 minutes**, whichever comes first; otherwise it exits after one query. Change the thresholds with `embedding.autoBatchSize` / `embedding.autoMaxWaitMinutes` in `.env`. `php spark embed:auto --dry-run` shows what is waiting without running anything, and a lock stops a slow run overlapping the next one.

  A run while `embedding-service/` is down only logs the failure; the next run catches up.
- Gigs are embedded live, inside the match request, and `matcher/` saves the vector to `gig_embeddings` only after it has returned the matches (so saving never counts against the 5 s). Nothing reads `gig_embeddings` during matching; it is kept for later use.
- Search with `App\Libraries\EmbeddingStore`:

  ```php
  EmbeddingStore::showcases()->nearest($gigVector, 50); // [['id' => 12, 'distance' => 0.21], ...], nearest first
  ```

  It runs `ORDER BY VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) LIMIT ?`. MariaDB uses the vector index only for that exact shape, so filter (status, category) on an over-fetched result rather than adding to the query. `php spark embed:similar <showcase_id> [--limit 10]` lists a showcase's nearest neighbours, as a quick read-only check.
- The test database (SQLite) gets the same tables with `embedding` as TEXT and no index; `EmbeddingStore` refuses to run there.

## Live matching (`matcher/`)

When a hirer posts a gig they land on `/gigs/{id}/matches`, which lists the best-matching showcases within 5 s:

```
gig posted -> Gigs::matches -> MatcherClient: POST matcher /match (ranker request, 5 s timeout)
  matcher: embed the gig live (embedding-service /embed/gigs)
           -> refined BM25 || cosine over every published, embedded showcase -> RRF (k=60) -> top 50
           -> 17 features, z-scored per gig -> LambdaMART (XGBoost) -> ranked showcases as JSON
  after the response: matcher saves the gig's vector to gig_embeddings
```

The request and response are the ranker's API contract (`ranker/API_IO_SPEC.md`, example `ranker/example_io.json`). `MatcherClient::gigRequest()` builds the request from the gig and `GigModel::rankerTaxonomy()`:

```json
{"hire_id": 42, "hire_title": "...", "hire_description": "...", "industry": null,
 "taxonomy": [{"name": "Finance", "group": null,
               "specialisations": [{"name": "Due Diligence", "search_tag": "Due Diligence (Finance)", "skills": []}]}],
 "top_k": 10}
```

and the response is `{"results": [{"rank", "provider_id", "score", "raw_margin", "provider": {"display_title", "industry", "bio_excerpt"}, "signals": {"retrieval", "business_fit", "taxonomy"}}], "meta": {...}}`. Here `provider_id` is the `showcases.id`. `score` is the ranking score min-maxed to 0-100 per gig (rank 1 is always 100), not a probability; `signals` are the model's actual inputs.

`matcher/` runs the ranker's own pipeline, unchanged: `matcher/pipeline/` holds verbatim copies of the ranker files it needs and the shipped model (`v5tax_tags_xgb.json`), and `matcher/RANKER_VERSION` records the ranker commit they came from. It never embeds showcases: `embed:batch showcases` stores their vectors and the records they were embedded from (`fields_json`) in MariaDB, and the matcher loads them at startup and reloads them in the background when they change (checked every 30 s). A match takes about 0.1-0.5 s (gig embedding plus ranking over ~3,000 showcases); if `embedding-service/` is down it answers 503 and the page says matching is unavailable. An embedding-service left idle for a long while can take ~4 s on its next call (its model gets paged out), more than the matcher's 3 s budget for it, so the matcher embeds a dummy gig at startup and every 2 minutes to keep it warm.

Run it alongside `php spark serve` and `embedding-service/` (it reads the database and `embedding.baseURL` settings from this `.env`, and writes only to `gig_embeddings`):

```bash
cd matcher
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
uvicorn app:app --port 8002
```

The service URL is `matcher.baseURL` in `.env`. `GET /health` reports how many showcases it is matching against.

To check that it reproduces the ranker exactly, point it at a ranker checkout instead of the database and replay the ranker's example request:

```bash
MATCHER_CORPUS=../../ranker uvicorn app:app --port 8002      # matches against the ranker's 3,024 providers
curl -X POST localhost:8002/match -H 'Content-Type: application/json' \
     -d "$(python3 -c "import json; print(json.dumps(json.load(open('../../ranker/example_io.json'))['request']))")"
```

The response has `example_io.json`'s structure and the same providers in the same order. Rank 10's score differs slightly from that file because the file was scored from the ranker's precomputed feature tables; it equals what the ranker's own live `serve.py` returns.

To update to a newer ranker: copy the same files from `ranker/pipeline/` into `matcher/pipeline/` (including the model under `models_data/`), put the new commit in `RANKER_VERSION`, and run the parity check, which compares embeddings, features and the `example_io.json` contract with the ranker (needs `embedding-service/` running):

```bash
cd matcher && .venv/bin/python check_parity.py --ranker ../../ranker
```

Gigs and showcases are tagged with the same SkillsFuture taxonomy (`sf_v1`) the ranker was trained on, skills included, so all of its tag features (category, specialisation and skill overlap, and `skill_soft`) see real values. The matcher embeds every taxonomy skill name once at startup for `skill_soft` (cached under `matcher/cache/`).

## Taxonomy (SkillsFuture `sf_v1`)

Both forms tag a gig / showcase as the Data team's records do: up to 2 **categories** (SkillsFuture sectors), 1-3 **specialisations** (tracks) under each, and up to 8 **skills** per specialisation, chosen from the skills linked to it. Search tags are `"<specialisation> (<category>)"`, derived rather than stored. `App\Libraries\Taxonomy` serves the picker, enforces those caps and returns a record's tags in the records' / matcher request's shape.

- Tables: `sf_categories` (with `group_name`, the records' `group`, currently always null), `sf_specialisations`, `sf_skills`, `sf_specialisation_skills`. A gig / showcase stores `{gig,showcase}_specialisations` and `{gig,showcase}_skills`; its categories are those specialisations' parents. The client's legacy `categories` / `specialities` tables are no longer used.
- Load or refresh the taxonomy (additive, safe to re-run):

  ```bash
  php spark taxonomy:import providers.json hirers.json   # the Data team's tagged records (not kept in the repo)
  ```

- The forms' taxonomy picker is cached for a day (`Taxonomy::cachedTree()`); `taxonomy:import` clears it. After editing the `sf_*` tables by hand (e.g. in Layerbase), run `php spark cache:clear`.

## Adding a page

1. Create the view under `app/Views/`, extending the shared layout:
   ```php
   <?= $this->extend('layout/main') ?>
   <?= $this->section('content') ?>
   <h1 class="text-2xl font-bold">Hello</h1>
   <?= $this->endSection() ?>
   ```
2. Add a controller method in `app/Controllers/` returning `view('yourview')`.
3. Register the route in `app/Config/Routes.php`.

## Notes

- `.env` is gitignored — every teammate keeps their own copy. Only `.env.example` is committed.
- Tailwind is loaded via the Play CDN (compiles in the browser). Fine for development; swap for a compiled build before shipping.
