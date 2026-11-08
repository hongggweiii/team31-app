"""
AI tag suggestion for gigs and showcases, and the governance of terms the
taxonomy doesn't have yet, on the app's SkillsFuture
taxonomy (sf_* tables: category -> specialisation -> skills).

    GET  /health
    POST /suggest                  gig / showcase text -> the taxonomy's tags that fit it ("tags"), and the terms
                                   it lacks, stored as pending_review proposals and returned at once ("pending")
    POST /lookup                   is this name in the taxonomy? (exact match, else a close spelling match)
    POST /proposals                a term a user typed: its existing match, or a new pending proposal
    POST /proposals/attach         binds proposals suggested before their gig / showcase was saved
    GET  /proposals                the review queue, with duplicate / synonym flags
    POST /proposals/{id}/approve   adds the term to the taxonomy, tags every gig / showcase holding it
    POST /proposals/{id}/reject    soft-deletes it, detaches it from every gig / showcase, notifies their users
    POST /refresh                  reloads the taxonomy after it was edited elsewhere; re-screens pending proposals

A pending term never enters the sf_* tables or a gig's / showcase's tags: its
taxonomy_proposals row holds it, with its source, confidence and gig / showcase,
until it is approved, so matcher/ only ever sees approved terms. Pending rows
holding the same term (same kind, parent and normalised name) are one decision:
approving or rejecting one applies to all of them.

What the ranker share is the SkillsFuture taxonomy (sf_v1), the list of categories, 
specialisations and skills. The ranker's data is tagged with it, and 
`php spark taxonomy:import` copies it from that data into the app's sf_* tables, 
which this service reads. The ranker's data came already tagged, so the ranker has 
no tagging or review step; this service adds both for the app (OpenAI suggestions, 
then a person reviews any new term). The tags it helps put on gigs and showcases 
are what matcher/ uses for the ranker's tag features. Settings and the database 
connection work as in matcher/app.py.

Run (from taxonomy-service/; the database, OPENAI_API_KEY and taxonomy.* settings
come from the app's ../.env):
    python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
    .venv/bin/uvicorn app:app --port 8003
"""
import json
import logging
import os
import secrets
import threading
import time
from contextlib import asynccontextmanager, contextmanager
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path
from typing import Literal

import pymysql
from fastapi import BackgroundTasks, Depends, FastAPI, Header, HTTPException, Query
from openai import OpenAI, OpenAIError
from pydantic import BaseModel, Field
from rapidfuzz.distance import Levenshtein

HERE = Path(__file__).resolve().parent
log = logging.getLogger("uvicorn.error")


def env(key: str, default: str | None = None) -> str | None:
    """A setting from the app's .env (environment variables win), read as matcher/ reads it."""
    if key in os.environ:
        return os.environ[key]
    path = HERE.parent / ".env"
    for line in path.read_text().splitlines() if path.exists() else []:
        k, sep, v = line.partition("=")
        if sep and k.strip() == key:
            return v.strip().strip("'\"")
    return default



MODEL = env("taxonomy.openaiModel", "gpt-4o-mini")            
OPENAI_TIMEOUT = float(env("taxonomy.openaiTimeout", "20"))    # seconds; on failure the app shows the manual picker
CACHE_SECONDS = int(env("taxonomy.cacheSeconds", "86400"))     # how long the in-memory taxonomy is kept
FUZZY_PCT = int(env("taxonomy.fuzzySimilarityPct", "85"))      # how alike (0-100) two names must be to count as a close match
SERVICE_TOKEN = env("taxonomy.serviceToken", "")              # when set, callers must send it as X-Service-Token
PROMPTS = {p.stem: p.read_text() for p in (HERE / "prompts").glob("*.txt")}

MAX_CATEGORIES, MAX_SPECIALISATIONS, MAX_SKILLS = 2, 3, 8  # App\Libraries\Taxonomy::MAX_*
OWNERS = ("gig", "showcase")
KIND_TABLE = {"category": "sf_categories", "specialisation": "sf_specialisations", "skill": "sf_skills"}
NO_MATCH = ("none", None, None, None, None)  # auto_flag, closest_match_table / _id / _name, similarity_score


def now() -> str:
    """The current time in UTC, as text in the format the database stores dates in."""
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")  # the app's appTimezone is UTC


# ---- MariaDB ---------------------------------------------------------------------

class Db:
    """One connection, shared under a lock (plain `def` routes run in FastAPI's threadpool).
    The SQL in this file is also valid SQLite, which check_service.py tests on: %s
    placeholders, and no INSERT IGNORE, ON DUPLICATE KEY or NOW()."""

    IDLE_PING_SECONDS = 30  # a remote round trip each: only check a connection that has sat idle

    def __init__(self):
        """Starts without a connection; the first query opens one."""
        self.conn = None
        self.lock = threading.RLock()
        self.used = 0.0

    def cursor(self):
        """Returns a cursor on the shared database connection, opening the connection the first
        time. If the connection has been idle for more than IDLE_PING_SECONDS, it is checked
        first and reopened if it has dropped."""
        if self.conn is None:
            self.conn = pymysql.connect(host=env("database.default.hostname"), port=int(env("database.default.port", "3306")),
                                        user=env("database.default.username"), password=env("database.default.password"),
                                        database=env("database.default.database"), charset="utf8mb4", autocommit=True,
                                        cursorclass=pymysql.cursors.DictCursor)
        elif time.monotonic() - self.used > self.IDLE_PING_SECONDS:
            self.conn.ping(reconnect=True)
        self.used = time.monotonic()
        return self.conn.cursor()

    def query(self, sql: str, args=None) -> list[dict]:
        """Runs a query that reads data, and returns the rows, each as a dict."""
        with self.lock, self.cursor() as cur:
            cur.execute(sql, args)
            return list(cur.fetchall())

    def execute(self, sql: str, args=None) -> int:
        """Runs a write; returns the new row's id after an INSERT."""
        with self.lock, self.cursor() as cur:
            cur.execute(sql, args)
            return cur.lastrowid

    @contextmanager
    def transaction(self):
        """Use as `with db.transaction():`. Everything done inside is saved together at the end,
        or undone completely if an error happens."""
        with self.lock:
            self.cursor().close()  # (re)connect before BEGIN
            self.conn.begin()
            try:
                yield
            except BaseException:
                self.conn.rollback()
                raise
            self.conn.commit()


db = Db()


def ids_in(ids) -> str:
    """Makes the placeholders for an SQL `IN (...)` list: one %s per id, separated by commas."""
    return ", ".join(["%s"] * len(ids))


def proposals(where: str, args=()) -> list[dict]:
    """Returns the taxonomy_proposals rows that match the given SQL condition, oldest first."""
    return db.query(f"SELECT * FROM taxonomy_proposals WHERE {where} ORDER BY id", args)


# ---- the taxonomy, in memory ---------------------------------------------------

class Snapshot:
    """The sf_* tables: what the prompts list, and what lookups and screening compare against."""

    def __init__(self, categories: list[dict], specialisations: list[dict], skills: list[dict], links: list[dict]):
        """Stores the taxonomy's categories, specialisations and skills by id, and writes the
        whole taxonomy out as text. That text goes at the start of every prompt sent to
        OpenAI."""
        self.categories = {r["id"]: r for r in categories}    # id -> {id, name, group_name}
        self.specs = {r["id"]: r for r in specialisations}    # id -> {id, category_id, name}
        self.skills = {r["id"]: r["name"] for r in skills}
        self.links: dict[int, list[int]] = {}                 # specialisation id -> its skill ids
        for r in links:
            self.links.setdefault(r["specialisation_id"], []).append(r["skill_id"])
        self.loaded = time.monotonic()
        # First in every prompt, unchanged between calls, so OpenAI's prompt caching serves it.
        lines = ["TAXONOMY (SkillsFuture sf_v1): each category, its specialisations, and the skills linked to each."]
        for c in sorted(self.categories.values(), key=lambda c: c["name"]):
            lines.append(f"\n## {c['name']}")
            for s in sorted((s for s in self.specs.values() if s["category_id"] == c["id"]), key=lambda s: s["name"]):
                names = sorted(self.skills[k] for k in self.links.get(s["id"], []))
                lines.append(f"- {s['name']}: {'; '.join(names)}" if names else f"- {s['name']}")
        self.prompt = "\n".join(lines)

    def names(self, kind: str, parent_id: int | None = None) -> list[tuple[int, str]]:
        """(id, name) of the items of this kind; under parent_id when given (a specialisation's
        category, or the specialisation a skill is linked to)."""
        if kind == "category":
            return [(i, c["name"]) for i, c in self.categories.items()]
        if kind == "specialisation":
            return [(i, s["name"]) for i, s in self.specs.items() if parent_id is None or s["category_id"] == parent_id]
        return [(i, self.skills[i]) for i in (self.skills if parent_id is None else self.links.get(parent_id, []))]


_taxonomy: Snapshot | None = None


def load_taxonomy() -> Snapshot:
    """Reads the taxonomy from the database (the sf_* tables) and makes it the copy the service
    uses."""
    global _taxonomy
    _taxonomy = Snapshot(db.query("SELECT id, name, group_name FROM sf_categories"),
                         db.query("SELECT id, category_id, name FROM sf_specialisations"),
                         db.query("SELECT id, name FROM sf_skills"),
                         db.query("SELECT specialisation_id, skill_id FROM sf_specialisation_skills"))
    return _taxonomy


def taxonomy() -> Snapshot:
    """Returns the taxonomy the service is using. It is read from the database again if it is
    older than CACHE_SECONDS."""
    if _taxonomy is None or time.monotonic() - _taxonomy.loaded > CACHE_SECONDS:
        return load_taxonomy()
    return _taxonomy


# ---- matching names: exact, fuzzy -------------------------------------------------

def normalise(name: str) -> str:
    """Puts a name in a standard form for comparing: case, spacing and trailing punctuation don't make a different term."""
    return " ".join(name.casefold().split()).rstrip(" .,;:!?")


def similarity(a: str, b: str) -> int:
    """How alike two names are, 0-100: 100 * (1 - Levenshtein distance / the longer length)."""
    return round(Levenshtein.normalized_similarity(normalise(a), normalise(b)) * 100)


def best_match(name: str, items: list[tuple]) -> tuple[str, tuple, int] | None:
    """('exact', item, 100) for the first item named the same once normalised, else ('fuzzy', item,
    similarity) for the most similar at FUZZY_PCT or more, else None. Items are tuples, name last."""
    key = normalise(name)
    for item in items:
        if normalise(item[-1]) == key:
            return "exact", item, 100
    score, item = max(((similarity(name, i[-1]), i) for i in items), key=lambda x: x[0], default=(0, None))
    return ("fuzzy", item, score) if score >= FUZZY_PCT else None


def lookup(name: str, kind: str, parent_id: int | None = None) -> dict:
    """Finds a name in the taxonomy: an exact match first, else the most similar name if it is at least FUZZY_PCT
    alike. Never creates anything."""
    hit = best_match(name, taxonomy().names(kind, parent_id))
    if hit is None:
        return {"found": False, "name": name}
    match_type, (item_id, item_name), score = hit
    return {"found": True, "id": item_id, "name": item_name, "match_type": match_type, "similarity": score}


def find_spec(name: str, category_id: int) -> dict:
    """A specialisation the model chose: under its category, else (paired with the wrong category)
    the one with exactly that name anywhere."""
    hit = lookup(name, "specialisation", category_id)
    if not hit["found"]:
        anywhere = lookup(name, "specialisation")
        hit = anywhere if anywhere.get("match_type") == "exact" else hit
    return hit


# ---- OpenAI ------------------------------------------------------------------------

_openai: OpenAI | None = None
AI_ERRORS = (OpenAIError, ValueError)


def openai_json(system: str, user: str, schema: dict) -> dict:
    """One Chat Completions call whose answer must match `schema` (structured outputs: always valid
    JSON, no code fences to strip). Raises one of AI_ERRORS on any failure, a missing key included."""
    global _openai
    if _openai is None:
        _openai = OpenAI(api_key=env("OPENAI_API_KEY"), timeout=OPENAI_TIMEOUT, max_retries=1)
    resp = _openai.chat.completions.create(
        model=MODEL, max_completion_tokens=1000, temperature=0,  # the same text gets the same tags
        messages=[{"role": "system", "content": system}, {"role": "user", "content": user}],
        response_format={"type": "json_schema", "json_schema": {"name": "answer", "strict": True, "schema": schema}})
    message = resp.choices[0].message
    if not message.content:
        raise ValueError(f"no answer ({message.refusal or resp.choices[0].finish_reason})")
    return json.loads(message.content)


def obj(**properties) -> dict:
    """A structured-outputs object: every property required, no others."""
    return {"type": "object", "additionalProperties": False, "required": list(properties), "properties": properties}


def suggest_schema(t: Snapshot) -> dict:
    """/suggest's answer. A tag's category and specialisation can only be names the taxonomy has."""
    categories = {"type": "string", "enum": sorted({c["name"] for c in t.categories.values()})}
    specialisations = {"type": "string", "enum": sorted({s["name"] for s in t.specs.values()})}
    text, number = {"type": "string"}, {"type": "number"}
    return obj(
        tags={"type": "array", "items": obj(category=categories, specialisation=specialisations,
                                            skills={"type": "array", "items": text}, confidence=number)},
        new_terms={"type": "array", "items": obj(kind={"type": "string", "enum": ["specialisation", "skill"]}, name=text,
                                                 category=categories, specialisation={"type": ["string", "null"]},
                                                 confidence=number)})


DUPLICATES_SCHEMA = obj(results={"type": "array", "items": obj(key={"type": "integer"},
                                                               same_as={"type": ["string", "null"]})})


# ---- duplicate / synonym screening ---------------------------------------------------

def save_flag(row: dict, flag: tuple) -> None:
    """Stores a screening result, unless the row already has it (re-screens leave most rows as they were)."""
    if tuple(row.get(k) for k in ("auto_flag", "closest_match_table", "closest_match_id", "closest_match_name",
                                  "similarity_score")) == flag:
        return
    db.execute("UPDATE taxonomy_proposals SET auto_flag = %s, closest_match_table = %s, closest_match_id = %s, "
               "closest_match_name = %s, similarity_score = %s WHERE id = %s", (*flag, row["id"]))


def screen(rows: list[dict], judge: set[int] | None = None) -> None:
    """Flags pending proposals before review, comparing each with the taxonomy's items of its kind
    and with every other pending proposal; the closest match is stored with the flag:

        exact    the same name once normalised
        fuzzy    FUZZY_PCT or more Levenshtein similarity
        synonym  the model judges it the same term: an abbreviation, synonym or reworded name.
                 (Embedding cosine can't do this: on the 825 skills, AML ~ Anti-Money Laundering
                 scores below Cloud Security ~ Cloud Computing.)

    Only rows in `judge` (every row when None) are sent to the model; any other row the first two
    checks don't catch keeps a synonym flag whose match still exists, else becomes none. If the
    model is unavailable, rows are flagged on the first two checks alone."""
    t = taxonomy()
    pending = db.query("SELECT id, kind, proposed_name FROM taxonomy_proposals WHERE status = 'pending_review'")
    to_judge = []
    for row in rows:
        others = [(KIND_TABLE[row["kind"]], i, n) for i, n in t.names(row["kind"])] + [
            ("taxonomy_proposals", p["id"], p["proposed_name"]) for p in pending if p["kind"] == row["kind"] and p["id"] != row["id"]]
        hit = best_match(row["proposed_name"], others)
        if hit:
            flag, item, score = hit
            save_flag(row, (flag, *item, score))
        elif judge is None or row["id"] in judge:
            to_judge.append((row, others))
        elif row["auto_flag"] != "synonym" or (row["closest_match_table"], row["closest_match_id"]) not in {o[:2] for o in others}:
            save_flag(row, NO_MATCH)
    for (row, others), same_as in zip(to_judge, judge_synonyms(to_judge)):
        hit = best_match(same_as, others) if same_as else None
        save_flag(row, ("synonym", *hit[1], None) if hit else NO_MATCH)


def judge_synonyms(items: list[tuple[dict, list]]) -> list[str | None]:
    """For each (row, others) from screen(), the name the model says it means the same as, from one
    call; all None when the model is unavailable."""
    if not items:
        return []
    terms = [{"key": k, "kind": row["kind"], "name": row["proposed_name"]} for k, (row, _) in enumerate(items)]
    pending = sorted({(row["kind"], o[2]) for row, others in items for o in others if o[0] == "taxonomy_proposals"})
    user = json.dumps({"terms": terms, "pending_terms": [{"kind": k, "name": n} for k, n in pending]}, ensure_ascii=False)
    try:
        answer = openai_json(taxonomy().prompt + "\n\n" + PROMPTS["duplicates"], user, DUPLICATES_SCHEMA)
    except AI_ERRORS as e:
        log.warning("synonym check skipped: %s", e)
        return [None] * len(items)
    same_as = {r["key"]: r["same_as"] for r in answer["results"]}
    return [same_as.get(k) for k in range(len(items))]


def rescreen(judge: set[int] = frozenset()) -> int:
    """Runs the duplicate and synonym check again on every pending proposal. Only the proposals
    whose ids are in `judge` are sent to OpenAI. Returns the number of pending proposals."""
    rows = proposals("status = 'pending_review'")
    screen(rows, set(judge))
    return len(rows)


# ---- proposals -------------------------------------------------------------------

def term_key(row: dict) -> tuple:
    """Pending rows with the same key hold the same term: one decision approves or rejects them all."""
    parent = row["specialisation_id"] if row["kind"] == "skill" else row["category_id"]
    return row["kind"], parent, normalise(row["proposed_name"])


def propose(name: str, kind: str, *, source: str, category_id: int | None = None, specialisation_id: int | None = None,
            user_id: int | None = None, owner_type: str | None = None, owner_id: int | None = None,
            confidence: float | None = None, model: str | None = None) -> tuple[dict, bool]:
    """(row, True) for a new pending_review proposal, not yet screened; or (row, False) for the same
    term already pending for this gig / showcase (or, before it is saved, for this user)."""
    t = taxonomy()
    name = " ".join(name.split())[:255]
    if kind == "skill":
        if specialisation_id not in t.specs:
            raise HTTPException(422, "A new skill needs the id of an existing specialisation")
        category_id = t.specs[specialisation_id]["category_id"]
    elif category_id not in t.categories:
        raise HTTPException(422, "A new specialisation needs the id of an existing category")
    else:
        specialisation_id = None
    row = {"kind": kind, "proposed_name": name, "category_id": category_id, "specialisation_id": specialisation_id}
    for p in proposals("status = 'pending_review' AND kind = %s", (kind,)):
        if (term_key(p) == term_key(row) and (p["owner_type"], p["owner_id"]) == (owner_type, owner_id)
                and (owner_id is not None or p["submitted_by_user"] == user_id)):
            return p, False
    new_id = db.execute(
        "INSERT INTO taxonomy_proposals (proposed_name, kind, category_id, specialisation_id, status, submitted_by_source, "
        "submitted_by_user, confidence, model, owner_type, owner_id, auto_flag, created_at) "
        "VALUES (%s, %s, %s, %s, 'pending_review', %s, %s, %s, %s, %s, %s, 'none', %s)",
        (name, kind, category_id, specialisation_id, source, user_id,
         None if confidence is None else round(min(max(confidence, 0.0), 1.0), 3), model, owner_type, owner_id, now()))
    return proposals("id = %s", (new_id,))[0], True


def public(row: dict) -> dict:
    """A taxonomy_proposals row as JSON, with its category's and specialisation's names."""
    t = taxonomy()
    out = {k: (float(v) if isinstance(v, Decimal) else str(v) if isinstance(v, datetime) else v) for k, v in row.items()}
    out["category"] = t.categories.get(row["category_id"], {}).get("name")
    out["specialisation"] = t.specs.get(row["specialisation_id"], {}).get("name")
    return out


def pending_group(proposal_id: int) -> list[dict]:
    """The pending rows holding this proposal's term, it first. 404 / 409 unless it is pending."""
    rows = proposals("id = %s", (proposal_id,))
    if not rows:
        raise HTTPException(404, f"No proposal {proposal_id}")
    if rows[0]["status"] != "pending_review":
        raise HTTPException(409, f"Proposal {proposal_id} is already {rows[0]['status']}")
    key = term_key(rows[0])
    return rows + [p for p in proposals("status = 'pending_review' AND kind = %s AND id <> %s", (rows[0]["kind"], proposal_id))
                   if term_key(p) == key]


def resolve(answer: dict, t: Snapshot) -> tuple[dict, list]:
    """Splits the model's answer into what the taxonomy has (looked up) and what it doesn't.

    Returns {specialisation id: {"confidence", "skills": [skill ids]}}, most confident first and
    capped as Taxonomy::check() caps a form, and the unmatched terms as (kind, name, category id,
    specialisation id, confidence). Categories are SkillsFuture sectors: never proposed. A skill the
    taxonomy has under another specialisation is not new, so it is dropped rather than proposed."""
    chosen: dict[int, dict] = {}
    unmatched: list[tuple] = []

    def take(spec_id: int, confidence: float) -> dict | None:
        """Adds a specialisation to the tags being chosen, unless that would go over the form's
        limits (at most MAX_CATEGORIES categories, and MAX_SPECIALISATIONS specialisations in
        each). Returns the specialisation's entry, or None if it was not added."""
        cat = t.specs[spec_id]["category_id"]
        cats = [t.specs[s]["category_id"] for s in chosen]
        if spec_id in chosen or ((cat in cats or len(set(cats)) < MAX_CATEGORIES) and cats.count(cat) < MAX_SPECIALISATIONS):
            return chosen.setdefault(spec_id, {"confidence": confidence, "skills": []})
        return None

    for tag in sorted(answer["tags"], key=lambda x: -x["confidence"]):
        cat = lookup(tag["category"], "category")
        spec = find_spec(tag["specialisation"], cat["id"]) if cat["found"] else {"found": False}
        entry = take(spec["id"], tag["confidence"]) if spec["found"] else None
        for name in tag["skills"] if entry is not None else []:
            skill = lookup(name, "skill", spec["id"])
            if skill["found"]:
                if skill["id"] not in entry["skills"] and len(entry["skills"]) < MAX_SKILLS:
                    entry["skills"].append(skill["id"])
            elif not lookup(name, "skill")["found"]:  # the taxonomy has it under another specialisation: dropped
                unmatched.append(("skill", name, cat["id"], spec["id"], tag["confidence"]))

    for term in answer["new_terms"]:
        cat = lookup(term["category"], "category")
        if not cat["found"] or not term["name"].strip():
            continue
        if term["kind"] == "specialisation":
            spec = lookup(term["name"], "specialisation", cat["id"])
            if spec["found"]:
                take(spec["id"], term["confidence"])
            else:
                unmatched.append(("specialisation", term["name"], cat["id"], None, term["confidence"]))
            continue
        spec = find_spec(term["specialisation"] or "", cat["id"])
        if not spec["found"]:
            continue  # a new skill is only proposed under an existing specialisation
        skill = lookup(term["name"], "skill", spec["id"])
        if skill["found"]:
            if (entry := chosen.get(spec["id"])) and skill["id"] not in entry["skills"] and len(entry["skills"]) < MAX_SKILLS:
                entry["skills"].append(skill["id"])
        elif not lookup(term["name"], "skill")["found"]:  # not new if any specialisation lists it
            unmatched.append(("skill", term["name"], cat["id"], spec["id"], term["confidence"]))
    return chosen, unmatched


def tags_out(chosen: dict, t: Snapshot) -> list[dict]:
    """resolve()'s choice in Taxonomy::tags()'s shape (the matcher request's `taxonomy`), plus ids and
    the model's confidence."""
    by_category: dict[int, dict] = {}
    for spec_id, entry in chosen.items():
        spec = t.specs[spec_id]
        cat = t.categories[spec["category_id"]]
        by_category.setdefault(cat["id"], {"id": cat["id"], "name": cat["name"], "group": cat["group_name"],
                                           "specialisations": []})["specialisations"].append({
            "id": spec_id, "name": spec["name"], "search_tag": f"{spec['name']} ({cat['name']})",
            "confidence": entry["confidence"], "skills": [{"id": k, "name": t.skills[k]} for k in entry["skills"]]})
    return list(by_category.values())


def tag_owner(owner: str, owner_id: int, category_id: int, spec_id: int, skill_id: int | None, t: Snapshot) -> str | None:
    """Gives a gig / showcase an approved specialisation, or an approved skill under one of its
    specialisations, within the forms' caps. None when done (or it already had it), else why not."""
    if owner not in OWNERS or not db.query(f"SELECT id FROM {owner}s WHERE id = %s", (owner_id,)):
        return f"the {owner} no longer exists"
    held = [r["specialisation_id"] for r in db.query(
        f"SELECT specialisation_id FROM {owner}_specialisations WHERE {owner}_id = %s", (owner_id,))]
    if skill_id is not None:
        if spec_id not in held:
            return f"the {owner} no longer has that specialisation"
        skills = [r["skill_id"] for r in db.query(
            f"SELECT skill_id FROM {owner}_skills WHERE {owner}_id = %s AND specialisation_id = %s", (owner_id, spec_id))]
        if skill_id in skills:
            return None
        if len(skills) >= MAX_SKILLS:
            return f"the {owner} already has {MAX_SKILLS} skills under that specialisation"
        db.execute(f"INSERT INTO {owner}_skills ({owner}_id, specialisation_id, skill_id) VALUES (%s, %s, %s)",
                   (owner_id, spec_id, skill_id))
        return None
    if spec_id in held:
        return None
    cats = [t.specs[s]["category_id"] for s in held if s in t.specs]
    if category_id not in cats and len(set(cats)) >= MAX_CATEGORIES:
        return f"the {owner} already has {MAX_CATEGORIES} categories"
    if cats.count(category_id) >= MAX_SPECIALISATIONS:
        return f"the {owner} already has {MAX_SPECIALISATIONS} specialisations in that category"
    db.execute(f"INSERT INTO {owner}_specialisations ({owner}_id, specialisation_id) VALUES (%s, %s)", (owner_id, spec_id))
    return None


def owner_user(row: dict) -> int | None:
    """Returns the id of the user who owns the proposal's gig or showcase, or None if there is no
    such user."""
    if row["owner_type"] not in OWNERS:
        return None
    found = db.query(f"SELECT user_id FROM {row['owner_type']}s WHERE id = %s", (row["owner_id"],))
    return found[0]["user_id"] if found else None


def rejection_message(row: dict, reason: str | None) -> str:
    """Writes the message a user gets when their term is rejected: what was removed, the
    reviewer's reason, and an existing taxonomy term to use instead, if the duplicate check
    found one."""
    where = f" from your {row['owner_type']} #{row['owner_id']}" if row["owner_id"] is not None else ""
    text = f'The {row["kind"]} "{row["proposed_name"]}" was not added to the taxonomy, so it has been removed{where}.'
    if reason:
        text += f" Reason: {reason}"
    if row["auto_flag"] != "none" and row["closest_match_table"] != "taxonomy_proposals":
        text += f' You can use "{row["closest_match_name"]}" instead.'
    return text


# ---- HTTP ----------------------------------------------------------------------------

@asynccontextmanager
async def lifespan(_app: FastAPI):
    """Runs once when the service starts: loads the taxonomy and logs its size and the OpenAI
    model in use."""
    t = load_taxonomy()
    log.info("taxonomy: %d categories, %d specialisations, %d skills; model %s",
             len(t.categories), len(t.specs), len(t.skills), MODEL)
    yield


def check_token(x_service_token: str | None = Header(None)) -> None:
    """If a service token is set (taxonomy.serviceToken), rejects any request that doesn't send
    it in the X-Service-Token header (HTTP 401)."""
    if SERVICE_TOKEN and not secrets.compare_digest(x_service_token or "", SERVICE_TOKEN):
        raise HTTPException(401, "Missing or wrong X-Service-Token")


app = FastAPI(title="SenseiGigs taxonomy service", lifespan=lifespan, dependencies=[Depends(check_token)])

Owner = Literal["gig", "showcase"]


class SuggestRequest(BaseModel):
    """Request body for /suggest: the gig or showcase text to tag, and which gig or showcase and
    user it belongs to."""
    text: str = Field(min_length=1, max_length=20000)
    title: str | None = None
    owner_type: Owner | None = None  # what is being tagged
    owner_id: int | None = None      # once it is saved; else bind its proposals later with /proposals/attach
    user_id: int | None = None       # its owner, notified if one of its pending terms is rejected


class LookupRequest(BaseModel):
    """Request body for /lookup: a name to search the taxonomy for, and whether it is a category,
    specialisation or skill."""
    name: str = Field(min_length=1, max_length=255)
    kind: Literal["category", "specialisation", "skill"]
    parent_id: int | None = None  # a specialisation's category, or the specialisation a skill is under


class ProposalRequest(BaseModel):
    """Request body for /proposals: a new term a user typed into a form, and where it belongs in
    the taxonomy."""
    name: str = Field(min_length=1, max_length=255)
    kind: Literal["specialisation", "skill"]
    category_id: int | None = None        # a new specialisation's category
    specialisation_id: int | None = None  # a new skill's specialisation
    source: Literal["hirer", "provider"]
    user_id: int | None = None
    owner_type: Owner | None = None
    owner_id: int | None = None


class AttachRequest(BaseModel):
    """Request body for /proposals/attach: the proposals made before a gig or showcase was saved,
    and the gig or showcase they now belong to."""
    proposal_ids: list[int] = Field(min_length=1)
    owner_type: Owner
    owner_id: int
    user_id: int | None = None


class ReviewRequest(BaseModel):
    """Request body for approving or rejecting a proposal: who reviewed it, and why."""
    admin_user_id: int | None = None
    reason: str | None = Field(None, max_length=2000)


@app.get("/health")
def health():
    """Reports that the service is running, plus the OpenAI model, whether an OpenAI key is set,
    the size of the taxonomy and how many proposals are waiting for review."""
    t = taxonomy()
    pending = db.query("SELECT COUNT(*) AS n FROM taxonomy_proposals WHERE status = 'pending_review'")[0]["n"]
    return {"status": "ok", "model": MODEL, "openai_key": bool(env("OPENAI_API_KEY")), "categories": len(t.categories),
            "specialisations": len(t.specs), "skills": len(t.skills), "pending": pending}


@app.post("/suggest")
def suggest(req: SuggestRequest, background: BackgroundTasks):
    """`tags`: what the model chose that the taxonomy has (exact or fuzzy
    lookup), to pre-select. `pending`: every term it chose or proposed that the taxonomy lacks,
    stored as pending_review for this gig / showcase and returned now so the user sees them;
    they are screened for duplicates after the response. 503 when the model is unavailable:
    the caller shows the manual picker."""
    t = taxonomy()
    if not t.categories:
        raise HTTPException(503, "The taxonomy is empty: run php spark taxonomy:import")
    user = f"A {req.owner_type or 'gig or showcase'}.\n" + (f"Title: {req.title}\n" if req.title else "") + req.text
    try:
        answer = openai_json(t.prompt + "\n\n" + PROMPTS["suggest"], user, suggest_schema(t))
    except AI_ERRORS as e:
        log.warning("tag suggestion failed: %s", e)
        raise HTTPException(503, f"AI tag suggestion is unavailable: {e}") from e
    chosen, unmatched = resolve(answer, t)
    made = [propose(name, kind, source="ai_suggestion", category_id=cat, specialisation_id=spec, user_id=req.user_id,
                    owner_type=req.owner_type, owner_id=req.owner_id, confidence=conf, model=MODEL)
            for kind, name, cat, spec, conf in unmatched]
    background.add_task(screen, [row for row, is_new in made if is_new])
    ids = sorted({row["id"] for row, _ in made})
    return {"model": MODEL, "tags": tags_out(chosen, t),
            "pending": [public(r) for r in proposals(f"id IN ({ids_in(ids)})", ids)] if ids else []}


@app.post("/lookup")
def lookup_route(req: LookupRequest):
    """Searches the taxonomy for a name: an exact match first, then a close spelling match.
    See lookup()."""
    return lookup(req.name, req.kind, req.parent_id)


@app.post("/proposals")
def propose_route(req: ProposalRequest, background: BackgroundTasks):
    """A term a user typed: looked up first, so only a term the taxonomy lacks becomes a proposal."""
    hit = lookup(req.name, req.kind, req.specialisation_id if req.kind == "skill" else req.category_id)
    if hit["found"]:
        return {"found": True, "match": hit}
    row, is_new = propose(req.name, req.kind, source=req.source, category_id=req.category_id,
                          specialisation_id=req.specialisation_id, user_id=req.user_id,
                          owner_type=req.owner_type, owner_id=req.owner_id)
    if is_new:
        background.add_task(screen, [row])
    return {"found": False, "proposal": public(row)}


@app.post("/proposals/attach")
def attach(req: AttachRequest):
    """Links the proposals made while a gig or showcase was being created to it, once it has been
    saved. Only proposals that are still pending and not linked yet are changed. Returns the
    ids now linked to it."""
    ids = req.proposal_ids
    db.execute(f"UPDATE taxonomy_proposals SET owner_type = %s, owner_id = %s, submitted_by_user = COALESCE(submitted_by_user, %s) "
               f"WHERE id IN ({ids_in(ids)}) AND status = 'pending_review' AND owner_id IS NULL",
               (req.owner_type, req.owner_id, req.user_id, *ids))
    rows = proposals(f"id IN ({ids_in(ids)})", ids)
    return {"attached": [r["id"] for r in rows if (r["owner_type"], r["owner_id"]) == (req.owner_type, req.owner_id)]}


@app.get("/proposals")
def list_proposals(status: Literal["pending_review", "approved", "rejected"] = "pending_review",
                   owner_type: Owner | None = None, owner_id: int | None = None,
                   limit: int = Query(200, ge=1, le=1000)):
    """Pending: newest suggestion first; approved / rejected: most recently reviewed first. A pending
    row's `duplicates` are the other pending rows holding the same term, decided with it."""
    where, args = "status = %s", [status]
    if owner_type and owner_id is not None:
        where, args = where + " AND owner_type = %s AND owner_id = %s", args + [owner_type, owner_id]
    order = "id DESC" if status == "pending_review" else "actioned_at DESC, id DESC"
    rows = db.query(f"SELECT * FROM taxonomy_proposals WHERE {where} ORDER BY {order} LIMIT %s", (*args, limit))
    groups: dict[tuple, list[int]] = {}
    for p in proposals("status = 'pending_review'"):
        groups.setdefault(term_key(p), []).append(p["id"])
    return {"proposals": [public(r) | {"duplicates": [i for i in groups.get(term_key(r), []) if i != r["id"]]
                                       if r["status"] == "pending_review" else []} for r in rows]}


@app.post("/proposals/{proposal_id}/approve")
def approve(proposal_id: int, req: ReviewRequest, background: BackgroundTasks):
    """Adds the term to the taxonomy: a skill linked under its specialisation (a skill of that name
    elsewhere is reused, not duplicated), or a specialisation under its category. Then tags every
    gig / showcase that held it as pending (`skipped` says why one wasn't), and points proposals
    flagged against it at the new taxonomy item."""
    with db.transaction():
        group = pending_group(proposal_id)
        lead, t = group[0], load_taxonomy()
        name, kind, ids = lead["proposed_name"], lead["kind"], [r["id"] for r in group]
        same = lambda items: next((i for i, n in items if normalise(n) == normalise(name)), None)  # noqa: E731
        if kind == "skill":
            spec_id = lead["specialisation_id"]
            if spec_id not in t.specs:
                raise HTTPException(409, "Its specialisation no longer exists")
            item_id = same(t.names("skill")) or db.execute("INSERT INTO sf_skills (name) VALUES (%s)", (name,))
            if item_id not in t.links.get(spec_id, []):
                db.execute("INSERT INTO sf_specialisation_skills (specialisation_id, skill_id) VALUES (%s, %s)", (spec_id, item_id))
        else:
            if lead["category_id"] not in t.categories:
                raise HTTPException(409, "Its category no longer exists")
            item_id = spec_id = same(t.names("specialisation", lead["category_id"])) or db.execute(
                "INSERT INTO sf_specialisations (category_id, name) VALUES (%s, %s)", (lead["category_id"], name))
        attached, skipped = [], []
        for row in group:
            if row["owner_id"] is not None:
                owner = {"proposal_id": row["id"], "owner_type": row["owner_type"], "owner_id": row["owner_id"]}
                why = tag_owner(row["owner_type"], row["owner_id"], lead["category_id"], spec_id,
                                item_id if kind == "skill" else None, t)
                (skipped.append(owner | {"reason": why}) if why else attached.append(owner))
        db.execute(f"UPDATE taxonomy_proposals SET status = 'approved', approved_item_id = %s, admin_user_id = %s, "
                   f"admin_reason = %s, actioned_at = %s WHERE id IN ({ids_in(ids)})",
                   (item_id, req.admin_user_id, req.reason, now(), *ids))
        db.execute(f"UPDATE taxonomy_proposals SET closest_match_table = %s, closest_match_id = %s WHERE status = 'pending_review' "
                   f"AND closest_match_table = 'taxonomy_proposals' AND closest_match_id IN ({ids_in(ids)})",
                   (KIND_TABLE[kind], item_id, *ids))
    background.add_task(lambda: (load_taxonomy(), rescreen()))  # after the response: the reviewer doesn't wait
    return {"approved": ids, "item": {"table": KIND_TABLE[kind], "id": item_id, "name": name},
            "attached": attached, "skipped": skipped}


@app.post("/proposals/{proposal_id}/reject")
def reject(proposal_id: int, req: ReviewRequest, background: BackgroundTasks):
    """Soft-deletes the term (status rejected, deleted_at), which detaches it from every gig /
    showcase holding it (a pending term lives only in its proposal row), and leaves each affected
    user a user_notifications row. Proposals flagged as its synonyms are judged again."""
    with db.transaction():
        group = pending_group(proposal_id)
        ids, stamp = [r["id"] for r in group], now()
        db.execute(f"UPDATE taxonomy_proposals SET status = 'rejected', deleted_at = %s, actioned_at = %s, "
                   f"admin_user_id = %s, admin_reason = %s WHERE id IN ({ids_in(ids)})",
                   (stamp, stamp, req.admin_user_id, req.reason, *ids))
        detached, notified = [], []
        for row in group:
            owner = {"proposal_id": row["id"], "owner_type": row["owner_type"], "owner_id": row["owner_id"]}
            if row["owner_id"] is not None:
                detached.append(owner)
            user_id = row["submitted_by_user"] or owner_user(row)
            if user_id is not None:
                data = owner | {"name": row["proposed_name"], "kind": row["kind"], "reason": req.reason}
                db.execute("INSERT INTO user_notifications (user_id, type, message, data, created_at) "
                           "VALUES (%s, 'taxonomy_proposal_rejected', %s, %s, %s)",
                           (user_id, rejection_message(row, req.reason), json.dumps(data), stamp))
                notified.append(owner | {"user_id": user_id})
    flagged_against = proposals(f"status = 'pending_review' AND closest_match_table = 'taxonomy_proposals' "
                                f"AND closest_match_id IN ({ids_in(ids)})", ids)
    background.add_task(rescreen, {r["id"] for r in flagged_against})
    return {"rejected": ids, "detached": detached, "notified": notified}


@app.post("/refresh")
def refresh():
    """For the app after it edits the sf_* tables: reloads them and re-checks pending proposals."""
    t = load_taxonomy()
    return {"pending": rescreen(), "categories": len(t.categories), "specialisations": len(t.specs), "skills": len(t.skills)}
