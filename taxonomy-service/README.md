# taxonomy-service

AI tag suggestion for gigs and showcases, and review of terms the taxonomy doesn't have yet. It runs on port 8003. How to run it is at the top of `app.py`; how the endpoints work is [below](#how-the-endpoints-work).

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

## How the endpoints work

### The life of a new term

1. **Suggest.** A user fills in a gig or showcase form and clicks "Suggest tags with AI". The app calls `POST /suggest` with the text. The form isn't saved yet, so there is no gig or showcase id.
2. **Ask OpenAI.** The service sends OpenAI the whole taxonomy, the instructions in `prompts/suggest.txt` and the text. OpenAI replies with the tags that fit and any terms the taxonomy lacks.
3. **Split the answer.** Tags the taxonomy has go back to the form to be ticked. Terms it lacks are saved as **pending proposals** (rows in `taxonomy_proposals`, status `pending_review`) and also returned, so the user sees them straight away. Nothing is added to the taxonomy yet.
4. **Check for duplicates.** After the reply, each new proposal is compared with the taxonomy and with other pending proposals, and flagged if it looks like a duplicate (see [Duplicate checking](#duplicate-checking)).
5. **Attach.** When the user saves the form, the app calls `POST /proposals/attach` to link those proposals to the new gig or showcase.
6. **Review.** A reviewer opens `/taxonomy-dashboard`, which lists proposals with `GET /proposals`, and approves or rejects each one.
7. **Approve or reject.** An approved term is added to the taxonomy and tagged onto every gig or showcase that had it pending. A rejected term is removed from them, and their users get a notification.

A pending term lives only in its `taxonomy_proposals` row. It never goes into the `sf_*` tables or a gig's or showcase's tags until it is approved, so the matcher only ever sees approved terms.

Several pending rows can hold the same term: the same kind, the same parent, and the same name after ignoring case, spacing and trailing punctuation. These count as **one decision**: approving or rejecting one of them applies to all.

### Endpoints

| Endpoint | Called by the app from |
|---|---|
| `GET /health` | Nothing (for checking the service is up) |
| `POST /suggest` | `TagSuggestions` (the "Suggest tags with AI" button) |
| `POST /lookup` | Nothing yet |
| `POST /proposals` | Nothing yet |
| `POST /proposals/attach` | `BaseController::attachSuggestedTerms()`, after a gig or showcase is saved |
| `GET /proposals` | `TaxonomyDashboard` (the review page) |
| `POST /proposals/{id}/approve` | `TaxonomyDashboard` |
| `POST /proposals/{id}/reject` | `TaxonomyDashboard` (it sends an empty reason) |
| `POST /refresh` | `TaxonomyDashboard`, after a term is added, renamed or removed there |

#### `GET /health`

Reports that the service is running. It also returns the OpenAI model, whether an OpenAI key is set, how many categories, specialisations and skills the taxonomy has, and how many proposals are waiting for review.

#### `POST /suggest`

Suggests tags for a gig or showcase.

**Request**

```json
{"title": "AML review", "text": "Review our AML compliance framework against MAS Notice 626.",
 "owner_type": "gig", "owner_id": null, "user_id": 10}
```

- **`text`** is required, up to 20,000 characters.
- **`owner_type`** is `gig` or `showcase`.
- **`owner_id`** is the gig's or showcase's id, if it is already saved. Otherwise, link its proposals later with `/proposals/attach`.
- **`user_id`** is who gets notified if a term is rejected.

**What happens**

1. **Ask OpenAI.** The taxonomy, the instructions and the text are sent to OpenAI. The reply must follow a fixed JSON format, and its category and specialisation names must be ones the taxonomy has.
2. **Look up each skill.** Every skill name in the reply is looked up in the taxonomy, allowing for typos (see [`/lookup`](#post-lookup)). A known skill becomes a tag. An unknown skill becomes a pending proposal, unless the taxonomy has it under a different specialisation; then it is dropped.
3. **Keep to the form's limits.** At most 2 categories, 3 specialisations in each, and 8 skills under each specialisation. The most confident tags are kept.
4. **Handle new terms.** A new skill is proposed only under an existing specialisation, and a new specialisation only under an existing category. Categories are never proposed.
5. **Avoid duplicates.** Suggesting again for the same gig doesn't create duplicate proposals.

**Reply** (from `check_service.py`'s test taxonomy; the proposal row is shortened):

```json
{
  "model": "gpt-4o-mini",
  "tags": [{"id": 2, "name": "Financial Services", "group": null, "specialisations": [
    {"id": 3, "name": "Regulatory Compliance", "search_tag": "Regulatory Compliance (Financial Services)",
     "confidence": 0.92, "skills": [{"id": 4, "name": "AML/KYC Program Design"}, {"id": 5, "name": "Regulatory Reporting"}]}]}],
  "pending": [{"id": 1, "proposed_name": "Transaction Monitoring", "kind": "skill", "status": "pending_review",
               "submitted_by_source": "ai_suggestion", "confidence": 0.92, "owner_type": "gig", "owner_id": null,
               "auto_flag": "none", "category": "Financial Services", "specialisation": "Regulatory Compliance"}]
}
```

In this example:
- OpenAI wrote "Regulatry Reporting", and it was matched to "Regulatory Reporting".
- "Transaction Monitoring" isn't in the taxonomy, so it became a pending proposal.
- `auto_flag` is still `none` because the duplicate check runs after the reply.

**Errors.** If OpenAI fails, takes longer than `taxonomy.openaiTimeout`, or no key is set, the reply is **503**. The app then tells the user "Suggestions are unavailable right now.", and they pick tags by hand in the usual picker. An empty taxonomy also gives 503 ("run `php spark taxonomy:import`").

#### `POST /lookup`

Checks whether a name is in the taxonomy. It never creates anything.

**Request:** `{"name": "Regulatry Reporting", "kind": "skill", "parent_id": null}`
- **`kind`** is `category`, `specialisation` or `skill`.
- **`parent_id`** (optional) searches only under one category (for a specialisation) or one specialisation (for a skill).

**How it searches.** It looks for an exact match first, ignoring case, spacing and trailing punctuation. If there is none, it takes the closest spelling, if that is at least `taxonomy.fuzzySimilarityPct` (85) alike. Alike is measured as 100 × (1 − the number of letter edits ÷ the longer name's length).

**Reply:** `{"found": true, "id": 5, "name": "Regulatory Reporting", "match_type": "fuzzy", "similarity": 95}`, or `{"found": false, "name": ...}`.

#### `POST /proposals`

Records a term a user typed into a form.

**Request:**
- **`name`**, and **`kind`**: `specialisation` or `skill`.
- **The parent:** `category_id` for a new specialisation, or `specialisation_id` for a new skill.
- **`source`:** `hirer` or `provider`.
- **Optionally** `user_id`, `owner_type` and `owner_id`.

**What happens.** The name is looked up first, under its parent:
- **Found:** the reply is `{"found": true, "match": ...}`, so the existing term is used and no proposal is made.
- **Not found:** it becomes a pending proposal, or the existing one for the same gig or user is returned. The reply is `{"found": false, "proposal": ...}`, and the proposal is duplicate-checked after the reply.

If the term isn't found and its parent is missing or unknown, the reply is **422**.

#### `POST /proposals/attach`

Links proposals made before a gig or showcase was saved to it, now that it has an id.

**Request:** `{"proposal_ids": [1, 2], "owner_type": "gig", "owner_id": 42, "user_id": 10}`

Only proposals that are still pending and not linked to anything yet are changed.

**Reply:** `{"attached": [ids]}`, the ids now linked to that gig or showcase.

#### `GET /proposals`

Lists proposals for the review page.

**Query parameters:**
- **`status`:** `pending_review` (the default), `approved` or `rejected`.
- **`owner_type`** and **`owner_id`:** optionally, only one gig's or showcase's proposals.
- **`limit`:** 200 by default, at most 1000.

**Order.** Pending proposals come newest first; reviewed ones come most recently reviewed first.

**What each row includes:**
- its category and specialisation names;
- its duplicate flag (`auto_flag`, `closest_match_name`, `similarity_score`);
- **`duplicates`:** the other pending rows holding the same term, which will be decided together with it.

#### `POST /proposals/{id}/approve`

Adds the term to the taxonomy.

**Request:** `{"admin_user_id": 1, "reason": null}`

**What happens.** All of it happens in one database transaction, so a failure leaves nothing half done.

1. **The whole group is approved together:** every pending row holding the same term.
2. **The term is added:**
   - **A new skill** is linked under its specialisation. If a skill with that name already exists elsewhere, it is reused rather than created twice.
   - **A new specialisation** is added under its category.
3. **Every gig or showcase that had the term pending is tagged with it,** within the form's limits. Any that can't be tagged are listed in `skipped` with the reason, for example "the gig already has 2 categories".
4. **After the reply,** the service reloads the taxonomy and checks the remaining pending proposals again.

**Reply:** `{"approved": [ids], "item": {"table", "id", "name"}, "attached": [...], "skipped": [...]}`

**Errors:** **404** for an unknown id; **409** if it isn't pending any more, or its category or specialisation has since been deleted.

#### `POST /proposals/{id}/reject`

Rejects the term.

**Request:** `{"admin_user_id": 1, "reason": "Too specific"}`

**What happens.**

1. **The whole group is rejected together.** Each row is marked `rejected` with a `deleted_at` time. The rows are kept for the record, not deleted.
2. **The term disappears from its gigs and showcases.** A pending term only ever lived in its proposal row, so nothing else needs removing.
3. **Each affected user is notified** with a `user_notifications` row. It says what was removed, the reason, and an existing term to use instead, if the duplicate check found one.
4. **After the reply,** proposals that were flagged as matching this term are checked again.

**Reply:** `{"rejected": [ids], "detached": [...], "notified": [...]}`

**Errors:** **404** for an unknown id; **409** if it isn't pending any more.

#### `POST /refresh`

Re-reads the taxonomy from the database and checks every pending proposal again. The review page calls it after the taxonomy is edited there.

**Reply:** the number of pending proposals and the taxonomy's size.

### Duplicate checking

Each new proposal is compared with the taxonomy's terms of the same kind and with the other pending proposals. The result goes in `auto_flag`, with the closest match in `closest_match_name`:

| `auto_flag` | Meaning |
|---|---|
| `exact` | The same name, ignoring case, spacing and trailing punctuation |
| `fuzzy` | A spelling at least 85 alike (the same measure as `/lookup`) |
| `synonym` | OpenAI judged it to mean the same, e.g. an abbreviation ("AML" and "Anti-Money Laundering"), using `prompts/duplicates.txt`. Spelling can't catch these |
| `none` | Nothing similar found: probably a genuinely new term |

If OpenAI is unavailable, only the `exact` and `fuzzy` checks run.

### Settings and security

These settings are read from the app's `.env` in team31-app. In the handover package, they come from `taxonomy.env`, passed with `uvicorn --env-file ../taxonomy.env` (`handover/deployment-and-operations.md`); environment variables always win:

| Setting | Default | What it does |
|---|---|---|
| `OPENAI_API_KEY` | (none) | Needed for `/suggest` and the synonym check |
| `taxonomy.openaiModel` | `gpt-4o-mini` | The OpenAI model |
| `taxonomy.openaiTimeout` | `20` | Seconds to wait for OpenAI before giving up (503) |
| `taxonomy.cacheSeconds` | `86400` | How long the taxonomy is kept in memory before it is read from the database again |
| `taxonomy.fuzzySimilarityPct` | `85` | How alike (0–100) two names must be to count as a close match |
| `taxonomy.serviceToken` | (empty) | When set, every request must send it in the `X-Service-Token` header, or gets 401 |

## How it relates to the ranker

- **The taxonomy.** The ranker's corpus is tagged with the SkillsFuture taxonomy (`sf_v1`). `php spark taxonomy:import` loads it from that corpus into the `sf_*` tables. This service reads those tables, and an approved proposal adds to them.
- **The tags.** The tags this service helps put on gigs and showcases are what `matcher/` passes to the ranker's tag features (`tag_features.py`: `tag_level`, `spec_cov`, `skill_cov` and the rest). The ranker's corpus came already tagged, and it has no suggestion or review step.
- **`check_service.py --live`.** It reads the taxonomy from the ranker's `pipeline/data/data_set_v5_tax/{providers,hirers}.json`.
- **Conventions.** It follows `matcher/`: FastAPI, settings from the app's `.env` (`env()` is matcher's), and one shared MariaDB connection.
