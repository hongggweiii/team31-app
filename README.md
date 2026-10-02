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

## Showcase embeddings (`embedding-service/`)

Showcases are turned into vectors for matching by a small Python service, because the embedding model can't run in PHP. It copies the ranker's code unchanged (`provider_text` from `ranker/pipeline/corpus.py`, plus the encode and normalise steps from `retrieval_dense.py`), so the web app produces the same vectors the ranker was built on.

- Model: `mixedbread-ai/mxbai-embed-large-v1`, no query prefix (providers are the document side)
- Output: one vector per showcase, 1024 dimensions, unit length (use cosine or inner-product distance)
- Embedded text: the ranker's five provider fields, joined with spaces in this order, empty ones skipped. `ProviderEmbedder::fieldsFromShowcase()` fills them from the create-profile form's data, with rich text reduced to plain text:

| Ranker field | From the showcase |
|---|---|
| `about_title` | Headline |
| `about_description` | Bio (`about`) |
| `services_offered_title` | Every service title, `; `-separated |
| `services_offered_description` | Every service description |
| `relevant_experience` | Relevant achievements, one `- ` line each |

Nothing else is embedded: not Title, Credentials (which the ranker also leaves out), Availability, Rate, How I work, Technical proficiency, categories or documents.

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

- `showcase_embeddings` and `gig_embeddings`: one row per showcase / gig with `model`, `text_hash` (sha256 of the embedded text) and `embedding VECTOR(1024)`, under a `VECTOR INDEX ... DISTANCE=cosine`. They're separate tables because MariaDB allows one vector index per table, and gigs are embedded differently (query side). Rows cascade-delete with their showcase / gig.
- Embedding is offline and batched; nothing is embedded when a showcase is saved. Run on a schedule (needs `embedding-service/` up):

  ```bash
  php spark embed:batch showcases [--size 32] [--all]   # options take a space, not "="
  ```

  It pages through published showcases, skips any whose `text_hash` is unchanged, sends each page to `POST /embed/providers` in one request and upserts the vectors. Re-running is cheap. `--all` re-embeds everything (e.g. after a model change). Gigs need a gig embedder (the ranker's gig text plus the query prefix) that doesn't exist yet.
- Search with `App\Libraries\EmbeddingStore`:

  ```php
  EmbeddingStore::showcases()->nearest($gigVector, 50); // [['id' => 12, 'distance' => 0.21], ...], nearest first
  ```

  It runs `ORDER BY VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) LIMIT ?`. MariaDB uses the vector index only for that exact shape, so filter (status, category) on an over-fetched result rather than adding to the query. `php spark embed:similar <showcase_id> [--limit 10]` lists a showcase's nearest neighbours, as a quick read-only check.
- The test database (SQLite) gets the same tables with `embedding` as TEXT and no index; `EmbeddingStore` refuses to run there.

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
