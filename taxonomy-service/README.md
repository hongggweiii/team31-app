# taxonomy-service

AI tag suggestion for gigs and showcases, and review of terms the taxonomy doesn't have yet. It runs on port 8003. The endpoints and how to run it are at the top of `app.py`.

## Files

No code here comes from the ranker.

| File | What it is |
|---|---|
| `app.py` | The service: `/suggest`, `/lookup`, `/proposals` (create, attach, list, approve, reject) and `/refresh` |
| `check_service.py` | Tests every endpoint on a throwaway SQLite database with OpenAI faked. `--live` makes real OpenAI calls on the ranker's taxonomy |
| `prompts/suggest.txt` | The instructions for `/suggest`: choose tags from the taxonomy, and name terms it lacks |
| `prompts/duplicates.txt` | The instructions for the synonym check run on new proposals |
| `requirements.txt` | Package versions for this service |

The prompt files are sent to OpenAI as they are, so they hold no comments. The live taxonomy is put in front of each one.

## Who calls it

The PHP app, through `app/Libraries/TaxonomyClient.php`:
- the tag suggestions in the gig and showcase forms (`TagSuggestions`);
- the review page at `/taxonomy-dashboard` (`TaxonomyDashboard`).

## How it relates to the ranker

- **The taxonomy.** The ranker's corpus is tagged with the SkillsFuture taxonomy (`sf_v1`). `php spark taxonomy:import` loads it from that corpus into the `sf_*` tables. This service reads those tables, and an approved proposal adds to them.
- **The tags.** The tags this service helps put on gigs and showcases are what `matcher/` passes to the ranker's tag features (`tag_features.py`: `tag_level`, `spec_cov`, `skill_cov` and the rest). The ranker's corpus came already tagged, and it has no suggestion or review step.
- **`check_service.py --live`.** It reads the taxonomy from the ranker's `pipeline/data/data_set_v5_tax/{providers,hirers}.json`.
- **Conventions.** It follows `matcher/`: FastAPI, settings from the app's `.env` (`env()` is matcher's), and one shared MariaDB connection.
