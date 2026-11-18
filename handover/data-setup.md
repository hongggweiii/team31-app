# Data setup

Order matters: tables → taxonomy → tags → services → embeddings. Do it on staging first, run the checks, then repeat on production. `integration-steps.md` walks through the whole integration in order; this page covers the data.

## 1. Create the tables

Run the scripts in `sql/` (`sql/README.md`). If your profile and gig tables have other names or no `status` column, create the adapter views it describes first.

## 2. Load the SkillsFuture taxonomy

The seed is `seed/sf_taxonomy.sql`: **38 categories, 155 specialisations, 831 skills and 2,081 specialisation-skill links**. It is SkillsFuture `sf_v1` exactly as the model was trained on (154 specialisations, 825 skills, 2,073 links), plus the terms team31's AI tag review approved since: the specialisation "Cyber Trust Certification Guidance" (Infocomm Technology), six skills, and two existing skills linked under one more specialisation each.

Load it once, after `sql/01_taxonomy.sql`, into the empty `sf_*` tables:

```bash
mysql -h <host> -u <user> -p <database> < seed/sf_taxonomy.sql
```

It runs as one transaction and keeps team31's ids. Then check the counts (or run `testing/check.py database`, D5):

```sql
SELECT (SELECT COUNT(*) FROM sf_categories) AS categories,                -- 38
       (SELECT COUNT(*) FROM sf_specialisations) AS specialisations,       -- 155
       (SELECT COUNT(*) FROM sf_skills) AS skills,                         -- 831
       (SELECT COUNT(*) FROM sf_specialisation_skills) AS links;           -- 2081
```

The matcher builds its skill-vector cache from these 831 skills on its first start, so that start takes longer.

Load it before any gig or showcase is tagged. Don't edit the `sf_*` rows afterwards without re-checking tagged data: renaming or deleting an item changes or removes the tags that point at it (the foreign keys cascade). Adding terms is safe: that is all the optional term review does (`integration-guide.md`, G10). When the skill list grows, the matcher builds a new skill-vector cache at its next catalogue reload (after the next profile change), so that one reload takes longer.

## 3. Tag your existing gigs and showcases

The engine only understands SkillsFuture tags. If your gigs and showcases are tagged with your own categories today, they need SkillsFuture tags in `gig_specialisations` / `gig_skills` and `showcase_specialisations` / `showcase_skills` (see `integration-guide.md`, G4).

- **Build a mapping** from each of your categories to SkillsFuture specialisations. This is a one-off, manual piece of work: the names rarely line up one to one.
- **Untagged is acceptable.** A showcase or gig without tags is still matched on its text; it just gets no help from the tag features. You can tag gradually, for example by asking users to pick SkillsFuture tags the next time they edit.
- **New data:** switch your gig and showcase forms to the SkillsFuture picker, so everything saved from now on is tagged.

## 4. Start the services

See `deployment-and-operations.md`. The matcher starts fine on an empty catalogue: it answers every match with an empty list until showcases are embedded.

## 5. Embed every published showcase (first run)

Run your G2 job (`integration-guide.md`) over **every** published profile, in batches of up to 128. Make sure the job writes its timestamps on the same clock as your profiles (G2, "One clock") **before** this run.

- Speed depends on your hardware (a GPU is much faster than a CPU), so time one batch first and estimate from that. Run it outside peak hours if your catalogue is large. It is safe to stop and restart, because showcases that already have an up-to-date row are skipped.
- Showcases with no text at all are skipped. They can't be matched, so consider asking those users to complete their profile.
- The matcher picks the new rows up within 30 seconds; no restart is needed. `GET /health` on the matcher shows the count growing.

Then leave the job on its schedule (we run it every minute) so new and edited showcases keep flowing in.

## 6. Check

```bash
cd testing && python check.py database     # D7-D14: embeddings complete and correct
python check.py services                   # the engine answers correctly over your data
```

`D12` warns about any published showcase still without an embedding. `D14` confirms that the matcher sees the same number as the database.
