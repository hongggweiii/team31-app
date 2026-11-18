# The data generation repo

## What it is

The **BT4103-Scrape-and-Tag** repo (https://github.com/mcjuh/BT4103-Scrape-and-Tag) built the dataset the matching model was trained and evaluated on. It is the first link in the chain:

```
BT4103-Scrape-and-Tag            crawls pages, extracts gig and provider records with LLMs,
  data/output/                   tags them with SkillsFuture (sf_v1), grades gig-provider pairs
        │
        ▼
ranker (ranker.md)               trains and evaluates the model on those records
        │
        ▼
matcher/ (team31-app)            serves the trained model; the sf_* taxonomy your database
                                 loads is the part of sf_v1 those records use
```

| Part | What it is |
|---|---|
| `scraper/` | Crawls consulting and talent websites (130 start URLs) and saves each page as Markdown |
| `classifier_extractor/` | LLMs sort each page into provider profile, hirer gig or noise, then extract records in the ML schema (v2) and tag them with SkillsFuture. Person names are masked before a model sees a provider page |
| `classifier_extractor/taxonomy.py` | The SkillsFuture tagging rules: category = sector, specialisation = track within it, skills = the track's own skills; at most 2 sectors, 3 tracks and 8 skills per track |
| `enricher/` | Adds synthetic rate / budget, seniority and availability fields |
| `labeller/` | LLM relevance grading of gig-provider pairs (0-3), which became the ranker's training labels |
| `data/pages/` | The 13,415 classified pages: 2,381 provider, 1,298 hirer, 9,703 noise, 33 uncertain |
| `data/output/` | The datasets: 3,024 providers and 1,106 gigs (`providers.*`, `hirers.*`), the same corpus the ranker reports on, plus pair grades and 30 hand-written gigs (`crafted_gigs/`) |
| `data/reference/skillsfuture/` | The official SkillsFuture Singapore framework tables (sectors, tracks, job roles, 2,088 skills) that `sf_v1` is built from |
| `docs/` | `design.md` (every design decision and why), `schema_v2.md` (the record shape), `ranker_input_spec.md` |

The records are **synthetic or derived from public web pages**: no SenseiGigs user data is in them. The pipeline's LLM calls went to NUS SoC's SOCLAAS API, which you would replace with your own provider to rerun it.

## When you need it

**Not to integrate.** Nothing in team31-app reads it, and none of the integration steps use it.

You would use it only to:
1. **Extend the taxonomy properly.** `sf_v1` in your database is the part of the SkillsFuture framework the training records used (38 categories, 154 specialisations, 825 skills). The full framework tables are in `data/reference/skillsfuture/`. Adding terms from them (or through the optional term review, `integration-guide.md`, G10) is safe; the model scores tag matches on names, whatever the term.
2. **Retrain on more or different data.** Generate or relabel records here, then retrain in the ranker repo (`ranker.md`, "Upgrading"). Once you have real hirer-provider outcomes, replace the LLM grades with them: that is the most valuable upgrade (see the limits in `deployment-and-operations.md`).
3. **Understand a record.** Every training record keeps the model that produced it and its source page, so you can trace why a provider looks the way it does.

## Before you reuse it

- **Crawled content.** `data/pages/` holds text crawled from third-party websites (consulting firms, talent platforms) for research. Check the sites' terms before using or redistributing those pages commercially; the extracted records are rewritten, but derive from them.
- **Synthetic, LLM-judged data.** The model's reported quality (`ranker.md`) is measured on this data with LLM grades, not on real users. Re-validate on your own outcomes after launch.
