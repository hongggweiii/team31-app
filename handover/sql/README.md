# Database scripts

The scripts run on your MariaDB database. Use **MariaDB 11.7 or later**; we run 11.8. Older versions and MySQL have no `VECTOR` type.

| Script | Creates | Written by | Read by |
|---|---|---|---|
| `01_taxonomy.sql` | `sf_categories`, `sf_specialisations`, `sf_skills`, `sf_specialisation_skills` | the taxonomy seed, once (pending; see `../data-setup.md`) | your forms, the matcher |
| `<variant>/02_tag_links.sql` | `showcase_specialisations`, `showcase_skills`, `gig_specialisations`, `gig_skills` | your app, when a gig or profile is saved | the matcher (profiles); your app (gigs, for the `/match` request) |
| `<variant>/03_embeddings.sql` | `showcase_embeddings`, `gig_embeddings` | your app (profiles); the matcher (gigs) | the matcher |
| `<variant>/04_run_records.sql` (**optional**, G8) | `analytics_outputs`, `run_outputs` | your app, after each match | your matcher dashboard |
| `<variant>/05_taxonomy_review.sql` (**optional**, G9-G11) | `taxonomy_proposals`, `user_notifications` | taxonomy-service | taxonomy-service; your app (notifications) |

Scripts 01-03 are what matching needs. Run 04 and 05 only if you build the optional glue they belong to (`../integration-guide.md`, "Optional glue").

Our names: a **showcase** is a provider's profile, and a **gig** is a hirer's job post. Yours may be called something else; Step 1 deals with that.

## Step 1: what the engine needs from your tables

The matcher reads your profiles table directly, and scripts 02 and 03 add foreign keys to your profile and gig tables. The engine expects these names:

| Table | Column | What the matcher needs |
|---|---|---|
| `showcases` | `id` | The id returned by `/match` as `provider_id`. `INT UNSIGNED` or `BIGINT UNSIGNED` |
| `showcases` | `status` | Only rows with `status = 'published'` are matched |
| `showcases` | `updated_at` | Must change whenever a profile is published, unpublished or edited. The matcher checks it every 30 s to know when to reload |
| `gigs` | `id` | The `hire_id` you send to `/match`. `gig_embeddings` stores it. Same type as `showcases.id` |
| `showcases`, `gigs` | `user_id` | **Only with taxonomy-service** (script 05): the owner it notifies when a pending term is rejected |

**If your tables already look like this,** skip to Step 2 and leave the scripts as they are.

**If your tables or columns are named differently** (for example `profiles` with an `is_published` flag and `jobs`), don't change the engine. Instead:

1. Create two **views** with the names and columns the engine reads, over your real tables. Adapt the conditions to your app: everything that should **not** be matched must come out as something other than `'published'`. That includes drafts, soft-deleted rows, suspended accounts, and anything else your app hides.

   ```sql
   CREATE VIEW showcases AS
     SELECT id,
            CASE WHEN is_published = 1 AND deleted_at IS NULL THEN 'published' ELSE 'hidden' END AS status,
            GREATEST(COALESCE(updated_at, created_at), COALESCE(deleted_at, updated_at, created_at)) AS updated_at
     FROM profiles;

   CREATE VIEW gigs AS SELECT id FROM jobs;
   ```

   If you deploy taxonomy-service, add the owner's id to both views (`profiles.user_id AS user_id`, and the same for `jobs`). It reads them when it rejects a term. Its writes go to the tag tables, never to these views.

   The `COALESCE`s matter. In MariaDB, `GREATEST()` returns NULL if any argument is NULL, and older rows often have no `updated_at`. `updated_at` must change whenever the `status` the view computes changes. In this example, soft-deleting sets `deleted_at`, and the view's `updated_at` picks that up. If a profile can also be hidden by a change elsewhere (for example, its user being suspended), that change must bump the profile's `updated_at`, or the view must include that table's timestamp too.

2. In your copies of scripts 02, 03 and 04, change every `REFERENCES \`showcases\` (\`id\`)` to your **real** profile table and every `REFERENCES \`gigs\` (\`id\`)` to your real gig table. Foreign keys can't point at views. Deleting a real profile or gig then still removes its tags and embedding.

We tested this with a Laravel-style schema: `profiles` with `BIGINT UNSIGNED` ids, `is_published`, soft deletes, and `jobs`. The matcher, the checks and the cascades all worked unchanged.

**Choose the variant for your id type.** A foreign key column must have exactly the type of the id it points to. Check your real tables:

```sql
SELECT table_name, column_type FROM information_schema.columns
WHERE table_schema = DATABASE() AND column_name = 'id'
  AND table_name IN ('profiles', 'jobs');      -- your real profile and gig tables
```

| `column_type` of both tables | Use |
|---|---|
| `int(10) unsigned` or `int(11) unsigned` | `int-unsigned/` |
| `bigint(20) unsigned` (Laravel's default for `$table->id()`) | `bigint-unsigned/` |
| Anything else (signed `int`, signed `bigint`, or the two tables differ) | Copy the closer variant and change the type of every `showcase_id` / `gig_id` column to match yours exactly. Only those columns change: the `sf_*` ids stay `int(11) unsigned` |

`errno: 150 "Foreign key constraint is incorrectly formed"` from script 02 or 03 means one of two things. Either the variant doesn't match your id type, or a `REFERENCES` clause names a table that doesn't exist (or is a view).

## Step 2: run the scripts

```bash
mysql -h <host> -u <user> -p <database> < 01_taxonomy.sql
mysql -h <host> -u <user> -p <database> < int-unsigned/02_tag_links.sql    # or bigint-unsigned/, or your edited copy
mysql -h <host> -u <user> -p <database> < int-unsigned/03_embeddings.sql
mysql -h <host> -u <user> -p <database> < int-unsigned/04_run_records.sql      # optional: G8
mysql -h <host> -u <user> -p <database> < int-unsigned/05_taxonomy_review.sql  # optional: taxonomy-service
```

In script 05, the user, gig and showcase id columns take your id type, but carry no foreign keys. Its only foreign keys point at the `sf_*` tables, so it needs no `REFERENCES` edits.

If you manage your schema with migrations (Laravel, Phinx, ...), put each script's statements in a migration as raw SQL (for example `DB::statement(...)`). Schema builders generally can't express MariaDB's `VECTOR` column and `VECTOR KEY`.

Each script fails if a table already exists, so it never overwrites data. MariaDB commits each `CREATE TABLE` on its own, so a script that stops halfway leaves the tables before the failing one in place. Drop the tables in reverse order, fix the cause and run the script again.

**Backups:** dump and restore these tables with MariaDB 11.7+ tools. Older `mysqldump` versions and MySQL can't restore `VECTOR` columns.

The text the matcher ranks does **not** come from your profile columns. It comes from `showcase_embeddings.fields_json`, which your app writes when it embeds a profile. So your profile schema can differ from ours (`../integration-guide.md`, G1).

## Check the result

```sql
-- MariaDB version: must be 11.7 or later
SELECT VERSION();

-- the 10 tables exist, plus your showcases / gigs (tables or views)
-- (add 'analytics_outputs','run_outputs','taxonomy_proposals','user_notifications' if you ran 04 / 05)
SELECT table_name, table_type FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('sf_categories','sf_specialisations','sf_skills','sf_specialisation_skills',
                     'showcase_specialisations','showcase_skills','gig_specialisations','gig_skills',
                     'showcase_embeddings','gig_embeddings','showcases','gigs');

-- the engine's view of your profiles: id, status ('published' or not), updated_at
SELECT status, COUNT(*), MAX(updated_at) FROM showcases GROUP BY status;

-- vector functions work: returns 1024
SELECT VEC_FromText(CONCAT('[', REPEAT('0,', 1023), '0]')) IS NOT NULL AS ok,
       LENGTH(VEC_FromText(CONCAT('[', REPEAT('0,', 1023), '0]'))) / 4 AS dims;

-- after seeding, all four counts are above 0 (expected totals: see ../data-setup.md, step 2)
SELECT (SELECT COUNT(*) FROM sf_categories)            AS categories,
       (SELECT COUNT(*) FROM sf_specialisations)       AS specialisations,
       (SELECT COUNT(*) FROM sf_skills)                AS skills,
       (SELECT COUNT(*) FROM sf_specialisation_skills) AS links;
```

Or run `testing/check.py database` (D1-D5).
