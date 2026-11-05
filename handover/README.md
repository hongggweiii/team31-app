# SenseiGigs recommendation engine: handover

This package lets you add our gig → expert matching to your own website. A hirer posts a gig and immediately sees the providers whose profiles fit it best, ranked by the model the Data team trained on SkillsFuture-tagged data.

**Start with `integration-steps.md`.** It takes you through the whole integration in order, with a check at the end of every step. The other documents hold the details it links to.

## Before you start

You need:
- **MariaDB 11.7 or later** for the database that holds your profiles and gigs. MySQL won't work: the engine needs MariaDB's `VECTOR` type.
- **Python 3.12 or later** for the two services.
- About 4 GB of free memory for them.
- A staging copy of your site.

`integration-steps.md`, step 0, has the details.

## What's in the package

```
senseigigz-engine/
├── README.md
├── handover/            these documents, the SQL scripts, the API specs and the tests
├── embedding-service/   service 1: turns text into vectors (port 8001)
├── matcher/             service 2: ranks your profiles for a gig (port 8002), with a pinned copy of the ranker
└── reference/           team31-app's PHP that the guide cites (CodeIgniter 4): read it, don't run it
```

**You run the two services as they are.** You don't change their code. If your tables are named differently from ours, a small adapter (two SQL views) bridges the gap (`sql/README.md`).

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

Our words: a **showcase** is a provider's profile, and a **gig** is a hirer's job post. The engine's tables and API use them.

## Documents

| File | Read it for |
|---|---|
| `integration-steps.md` | **The order to do everything in**, with "done when" checks |
| `integration-guide.md` | **The glue**, item by item: exact rules, code to copy, pitfalls |
| `sql/README.md` and `sql/` | Creating the tables, with variants for your id type and adapter views for your table names |
| `data-setup.md` | Loading the taxonomy, tagging existing data, the first embedding run |
| `deployment-and-operations.md` | Installing and running the services, logs, performance, runbook, known limits |
| `engine.env.example` | The services' settings (also read by the checks) |
| `openapi/` | The services' exact request and response formats (OpenAPI 3.1) |
| `testing/README.md` and `testing/` | The checks (`check.py`), the sample data and its harness, and the QA checklist (`test-cases.csv`) |
| `ranker.md` | What the Data team's ranker repo is, and when you would ever need it (only for engine upgrades) |

## Pending

- **Taxonomy seed:** which version of the SkillsFuture taxonomy to ship, and the import files (`data-setup.md`, step 2). Until then, integration stops at step 3.
- **Contacts:** who at team31 answers questions after the handover.

## Not in this package

- **The ranker repo:** the Data team's training workspace. You only need it to upgrade the engine to a new model (`ranker.md`).
- **team31-app's own website** (forms, pages, accounts): you integrate into your own site. `reference/` has the parts the guide cites.
- **AI tag suggestion and the taxonomy review dashboard** (team31-app's `taxonomy-service/`): not part of this handover.

## For team31: building the package

```bash
handover/make-package.sh senseigigz-engine.zip
```

This collects the two services (every file git doesn't ignore, from your working tree), these documents and the reference PHP. Build it from a clean, committed checkout, so the zip contains exactly what is committed.
