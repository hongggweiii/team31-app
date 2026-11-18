# SenseiGigs recommendation engine: handover

This package lets you add our gig → expert matching to your own website. A hirer posts a gig and immediately sees the providers whose profiles fit it best, ranked by the model the Data team trained on SkillsFuture-tagged data.

**Start with `integration-steps.md`.** It takes you through the whole integration in order, with a check at the end of every step. The other documents hold the details it links to.

## Before you start

You need:
- **MariaDB 11.7 or later** for the database that holds your profiles and gigs. MySQL won't work: the engine needs MariaDB's `VECTOR` type.
- **Python 3.12 or later** for the two engine services (3.10 or later is enough for the optional taxonomy-service).
- About 4 GB of free memory for them.
- Only for the optional AI tag suggestion: an **OpenAI API key**.
- A staging copy of your site.

`integration-steps.md`, step 0, has the details.

## What's in the package

```
senseigigz-engine/
├── README.md
├── handover/            these documents, the SQL scripts, the taxonomy seed, the API specs and the tests
├── embedding-service/   service 1: turns text into vectors (port 8001)
├── matcher/             service 2: ranks your profiles for a gig (port 8002), with a pinned copy of the ranker
├── taxonomy-service/    optional service 3: AI tag suggestion and the review of new taxonomy terms (port 8003)
└── reference/           team31-app's PHP that the guide cites (CodeIgniter 4): read it, don't run it
```

**You run the services as they are.** You don't change their code. If your tables are named differently from ours, a small adapter (two SQL views) bridges the gap (`sql/README.md`).

**You build the glue in your app** that connects your data to them. All of it is described, with exact rules and our code as reference, in `integration-guide.md`:

| | Glue |
|---|---|
| G1 | Turn a profile into the five text fields, its text and its hash |
| G2 | A background job that embeds new and edited profiles and stores the vectors |
| G3 | Bump `updated_at` on every change that matters: edits (including services, achievements and tags), publishing, unpublishing, deleting |
| G4 | Store the SkillsFuture tags chosen on your gig and profile forms |
| G5 | Build the `/match` request for a gig, including its taxonomy array |
| G6 | Show the results, and handle every outcome without an error page |
| G7 | Configure the two service URLs and timeouts |

**Optional extras**, each independent of the others. Matching works without them:

| | Glue | Gives you |
|---|---|---|
| G8 | Record each match; an admin dashboard over the records | Latency and outcome monitoring, and how often results share the gig's tags |
| G9 | "Suggest tags with AI" on your gig and profile forms (needs taxonomy-service and an OpenAI key) | Users pick SkillsFuture tags faster. Terms the taxonomy lacks are held for review |
| G10 | A review page for those new terms, **for reviewers only** (needs taxonomy-service) | Approved terms join the taxonomy and are tagged onto the gigs and profiles that asked for them |
| G11 | Show users the notifications left when a term they asked for is rejected (needs taxonomy-service) | Users learn why a pending tag disappeared, and what to use instead |

taxonomy-service has no notion of users or roles: **your app decides who may review terms** and who sees which notification (`integration-guide.md`, "Roles for G9-G11").

Our words: a **showcase** is a provider's profile, and a **gig** is a hirer's job post. The engine's tables and API use them.

## Documents

| File | Read it for |
|---|---|
| `integration-steps.md` | **The order to do everything in**, with "done when" checks |
| `integration-guide.md` | **The glue**, item by item: exact rules, code to copy, pitfalls |
| `seed/sf_taxonomy.sql` | The SkillsFuture taxonomy to load once (`data-setup.md`, step 2) |
| `sql/README.md` and `sql/` | Creating the tables, with variants for your id type and adapter views for your table names |
| `data-setup.md` | Loading the taxonomy, tagging existing data, the first embedding run |
| `deployment-and-operations.md` | Installing and running the services, logs, performance, runbook, known limits |
| `engine.env.example` | The two engine services' settings (also read by the checks) |
| `taxonomy.env.example` | The optional taxonomy-service's settings: its database user, OpenAI key and token |
| `openapi/` | The services' exact request and response formats (OpenAPI 3.1) |
| `testing/README.md` and `testing/` | The checks (`check.py`), the sample data and its harness, and the QA checklist (`test-cases.csv`) |
| `../taxonomy-service/README.md` | How the optional taxonomy-service works inside: the life of a new term, the duplicate check, its settings |
| `ranker.md` | What the Data team's ranker repo is, and when you would ever need it (only for engine upgrades) |
| `data-generation.md` | The repo that built the training data (BT4103-Scrape-and-Tag), and when you would ever need it (extending the taxonomy, retraining) |

## Not in this package

- **The ranker repo:** the Data team's training workspace. You only need it to upgrade the engine to a new model (`ranker.md`).
- **The data generation repo** (https://github.com/mcjuh/BT4103-Scrape-and-Tag): the crawled pages, the generated and graded training records, and the full SkillsFuture framework tables (`data-generation.md`).
- **team31-app's own website** (forms, pages, accounts): you integrate into your own site. `reference/` has the parts the guide cites.
