"""
Checks taxonomy-service on a throwaway SQLite database, never the app's MariaDB.

    .venv/bin/python check_service.py              # every endpoint, OpenAI faked
    .venv/bin/python check_service.py --live       # real OpenAI calls (OPENAI_API_KEY in ../.env) on the full
                                                   # SkillsFuture taxonomy from a ranker checkout (--ranker)

The tables are those the app's migrations create, cut down to the columns the service uses.

Only --live needs the ranker: it reads the full taxonomy
from the ranker's pipeline/data/data_set_v5_tax/{providers,hirers}.json.
"""
import argparse
import json
import sqlite3
import sys
from contextlib import contextmanager
from pathlib import Path

import openai
from fastapi.testclient import TestClient

import app as service

SCHEMA = """
CREATE TABLE sf_categories (id INTEGER PRIMARY KEY, name TEXT UNIQUE, group_name TEXT);
CREATE TABLE sf_specialisations (id INTEGER PRIMARY KEY, category_id INTEGER, name TEXT, UNIQUE (category_id, name));
CREATE TABLE sf_skills (id INTEGER PRIMARY KEY, name TEXT UNIQUE);
CREATE TABLE sf_specialisation_skills (specialisation_id INTEGER, skill_id INTEGER, PRIMARY KEY (specialisation_id, skill_id));
CREATE TABLE gigs (id INTEGER PRIMARY KEY, user_id INTEGER);
CREATE TABLE showcases (id INTEGER PRIMARY KEY, user_id INTEGER);
CREATE TABLE gig_specialisations (gig_id INTEGER, specialisation_id INTEGER, PRIMARY KEY (gig_id, specialisation_id));
CREATE TABLE gig_skills (gig_id INTEGER, specialisation_id INTEGER, skill_id INTEGER, PRIMARY KEY (gig_id, specialisation_id, skill_id));
CREATE TABLE showcase_specialisations (showcase_id INTEGER, specialisation_id INTEGER, PRIMARY KEY (showcase_id, specialisation_id));
CREATE TABLE showcase_skills (showcase_id INTEGER, specialisation_id INTEGER, skill_id INTEGER, PRIMARY KEY (showcase_id, specialisation_id, skill_id));
CREATE TABLE taxonomy_proposals (
  id INTEGER PRIMARY KEY, proposed_name TEXT NOT NULL, kind TEXT NOT NULL, category_id INTEGER, specialisation_id INTEGER,
  status TEXT NOT NULL DEFAULT 'pending_review', submitted_by_source TEXT NOT NULL, submitted_by_user INTEGER,
  confidence REAL, model TEXT, owner_type TEXT, owner_id INTEGER, auto_flag TEXT NOT NULL DEFAULT 'none',
  closest_match_table TEXT, closest_match_id INTEGER, closest_match_name TEXT, similarity_score INTEGER,
  approved_item_id INTEGER, admin_user_id INTEGER, admin_reason TEXT, created_at TEXT, actioned_at TEXT, deleted_at TEXT);
CREATE TABLE user_notifications (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, type TEXT NOT NULL, message TEXT NOT NULL,
  data TEXT, read_at TEXT, created_at TEXT);
"""

# category -> specialisation -> skills
FIXTURE = {
    "Accountancy": {"Assurance": ["Audit Compliance", "Financial Statements Analysis"], "Tax": ["Tax Computation"]},
    "Financial Services": {"Regulatory Compliance": ["AML/KYC Program Design", "Regulatory Reporting"],
                           "Digital and Data Analytics": ["Data Analytics", "Stakeholder Management"]},
    "Infocomm Technology": {"Data and Artificial Intelligence": ["Cloud Computing", "Data Analytics"]},
}


class SqliteDb(service.Db):
    """A stand-in for the service's database: a temporary SQLite database in memory with the same
    tables, so these checks never touch the real MariaDB."""
    def __init__(self):
        """Creates the in-memory database and its tables."""
        super().__init__()
        self.conn = sqlite3.connect(":memory:", isolation_level=None, check_same_thread=False)
        self.conn.row_factory = sqlite3.Row
        self.conn.executescript(SCHEMA)

    def query(self, sql, args=None):
        """Same as Db.query, but first converts the %s placeholders to SQLite's ?."""
        with self.lock:
            return [dict(r) for r in self.conn.execute(sql.replace("%s", "?"), args or ()).fetchall()]

    def execute(self, sql, args=None):
        """Same as Db.execute, but first converts the %s placeholders to SQLite's ?."""
        with self.lock:
            return self.conn.execute(sql.replace("%s", "?"), args or ()).lastrowid

    @contextmanager
    def transaction(self):
        """Same as Db.transaction, using SQLite's BEGIN, COMMIT and ROLLBACK."""
        with self.lock:
            self.conn.execute("BEGIN")
            try:
                yield
            except BaseException:
                self.conn.execute("ROLLBACK")
                raise
            self.conn.execute("COMMIT")


def load(db: SqliteDb, taxonomy: dict) -> None:
    """Fills the test database with a taxonomy given as {category: {specialisation: [skills]}},
    then makes the service read it."""
    skills: dict[str, int] = {}
    for category, specs in taxonomy.items():
        cat_id = db.execute("INSERT INTO sf_categories (name) VALUES (%s)", (category,))
        for spec, names in specs.items():
            spec_id = db.execute("INSERT INTO sf_specialisations (category_id, name) VALUES (%s, %s)", (cat_id, spec))
            for name in names:
                if name not in skills:
                    skills[name] = db.execute("INSERT INTO sf_skills (name) VALUES (%s)", (name,))
                db.execute("INSERT INTO sf_specialisation_skills VALUES (%s, %s)", (spec_id, skills[name]))
    service.load_taxonomy()


class FakeAI:
    """Stands in for service.openai_json: answers /suggest with `answer`, and the synonym check
    from `same_as` (term name -> existing name); raises `error` when set."""

    def __init__(self):
        """Starts with empty answers and no recorded calls."""
        self.answer, self.same_as, self.error, self.calls = {"tags": [], "new_terms": []}, {}, None, []

    def __call__(self, system, user, schema):
        """Pretends to be OpenAI. Records the call, raises `error` if one is set, and otherwise
        returns `answer` for a tag suggestion, or the `same_as` matches for a synonym check."""
        self.calls.append(("suggest" if "tags" in schema["properties"] else "duplicates", user))
        if self.error:
            raise self.error
        if "tags" in schema["properties"]:
            return self.answer
        terms = json.loads(user)["terms"]
        return {"results": [{"key": t["key"], "same_as": self.same_as.get(t["name"])} for t in terms]}


def check(label: str, ok: bool, detail="") -> None:
    """Prints PASS or FAIL for one check, and counts the failures in check.failed."""
    print(f"  {'PASS' if ok else 'FAIL'}  {label}" + (f"   ({detail})" if detail and not ok else ""))
    if not ok:
        check.failed += 1


check.failed = 0


def run_faked() -> None:
    """Tests every endpoint on the small sample taxonomy (FIXTURE), with OpenAI replaced by
    FakeAI, printing PASS or FAIL for each check."""
    db = service.db = SqliteDb()
    load(db, FIXTURE)
    t = service.taxonomy()
    spec = {s["name"]: i for i, s in t.specs.items()}
    cat = {c["name"]: i for i, c in t.categories.items()}
    for row in ("INSERT INTO gigs VALUES (1, 10)", "INSERT INTO gigs VALUES (2, 11)", "INSERT INTO showcases VALUES (1, 20)",
                f"INSERT INTO gig_specialisations VALUES (1, {spec['Regulatory Compliance']})",
                f"INSERT INTO showcase_specialisations VALUES (1, {spec['Assurance']})"):
        db.execute(row)
    ai = service.openai_json = FakeAI()
    client = TestClient(service.app)
    n_skills = lambda: db.query("SELECT COUNT(*) AS n FROM sf_skills")[0]["n"]  # noqa: E731
    pending = lambda: client.get("/proposals").json()["proposals"]  # noqa: E731
    by_name = lambda name: next(p for p in pending() if p["proposed_name"] == name)  # noqa: E731

    print("Lookup (brief §10.3, §14.1)")
    r = client.post("/lookup", json={"name": "regulatory reporting", "kind": "skill"}).json()
    check("case-insensitive exact match", r["found"] and r["match_type"] == "exact" and r["name"] == "Regulatory Reporting", r)
    r = client.post("/lookup", json={"name": "Regulatry Reporting", "kind": "skill"}).json()
    check("typo matches by Levenshtein >= 85%", r["found"] and r["match_type"] == "fuzzy" and r["similarity"] >= 85, r)
    r = client.post("/lookup", json={"name": "Software Engineering", "kind": "skill"}).json()
    check("unrelated term not found", r == {"found": False, "name": "Software Engineering"}, r)
    r = client.post("/lookup", json={"name": "Data Analytics", "kind": "skill", "parent_id": spec["Assurance"]}).json()
    check("a skill is looked up among its specialisation's skills", r["found"] is False, r)

    print("Suggest (deliverable a, brief §14.2)")
    ai.answer = {
        "tags": [{"category": "Financial Services", "specialisation": "Regulatory Compliance", "confidence": 0.92,
                  "skills": ["AML/KYC Program Design", "Regulatry Reporting", "Transaction Monitoring"]},
                 {"category": "Accountancy", "specialisation": "Digital and Data Analytics", "confidence": 0.4,  # wrong category
                  "skills": ["Data Analytics"]}],
        "new_terms": [{"kind": "skill", "name": "Sanctions Screening", "category": "Financial Services",
                       "specialisation": "Regulatory Compliance", "confidence": 0.8},
                      {"kind": "specialisation", "name": "Sustainable Finance", "category": "Financial Services",
                       "specialisation": None, "confidence": 0.6},
                      {"kind": "skill", "name": "data analytics", "category": "Financial Services",  # not new
                       "specialisation": "Digital and Data Analytics", "confidence": 0.5}]}
    before = n_skills()
    body = {"text": "Review our AML compliance framework against MAS Notice 626.", "owner_type": "gig", "owner_id": 1, "user_id": 10}
    r = client.post("/suggest", json=body).json()
    specs = {s["name"]: [k["name"] for k in s["skills"]] for c in r["tags"] for s in c["specialisations"]}
    check("matched tags, a typo resolved to the taxonomy's name",
          specs.get("Regulatory Compliance") == ["AML/KYC Program Design", "Regulatory Reporting"], specs)
    check("a specialisation paired with the wrong category is found under its own",
          specs.get("Digital and Data Analytics") == ["Data Analytics"], specs)
    check("tags are in Taxonomy::tags()'s shape", r["tags"][0]["specialisations"][0]["search_tag"]
          == "Regulatory Compliance (Financial Services)", r["tags"][0])
    names = sorted(p["proposed_name"] for p in r["pending"])
    check("unmatched and new terms come back as pending at once",
          names == ["Sanctions Screening", "Sustainable Finance", "Transaction Monitoring"], names)
    p = next(p for p in r["pending"] if p["proposed_name"] == "Sanctions Screening")
    check("stored pending_review with source, confidence and originating gig",
          (p["status"], p["submitted_by_source"], p["confidence"], p["owner_type"], p["owner_id"], p["specialisation"])
          == ("pending_review", "ai_suggestion", 0.8, "gig", 1, "Regulatory Compliance"), p)
    check("nothing added to the taxonomy", n_skills() == before)
    check("pending terms screened after the response (one synonym check)", [c[0] for c in ai.calls] == ["suggest", "duplicates"], ai.calls)
    client.post("/suggest", json=body)
    check("suggesting again for the same gig adds no duplicate rows", len(pending()) == 3, len(pending()))

    ai.answer = {"tags": [], "new_terms": []}
    r = client.post("/suggest", json={"text": "Help with finance stuff."})
    check("vague text: valid JSON, no tags, no crash", r.status_code == 200 and r.json()["tags"] == [] and r.json()["pending"] == [], r.text)
    ai.error = openai.APITimeoutError(request=None)
    r = client.post("/suggest", json={"text": "Anything"})
    check("OpenAI timeout -> 503, so the app shows the manual picker", r.status_code == 503, r.text)
    ai.error = None

    print("User-typed terms and duplicate / synonym flags (deliverable c, brief §14.3)")
    r = client.post("/proposals", json={"name": "audit compliance", "kind": "skill", "specialisation_id": spec["Assurance"],
                                        "source": "provider", "user_id": 20, "owner_type": "showcase", "owner_id": 1}).json()
    check("an existing term is returned, not proposed", r["found"] and r["match"]["name"] == "Audit Compliance", r)
    propose = lambda name, kind="skill", parent="Assurance": client.post("/proposals", json={  # noqa: E731
        "name": name, "kind": kind, "source": "provider", "user_id": 20, "owner_type": "showcase", "owner_id": 1,
        **({"specialisation_id": spec[parent]} if kind == "skill" else {"category_id": cat[parent]})}).json()
    propose("Data Analytics")
    p = by_name("Data Analytics")
    check("exact: a skill the taxonomy has under another specialisation",
          (p["auto_flag"], p["closest_match_table"], p["closest_match_name"]) == ("exact", "sf_skills", "Data Analytics"), p)
    propose("Sanction Screening", parent="Regulatory Compliance")
    p = by_name("Sanction Screening")
    check("fuzzy: a near-identical pending term", (p["auto_flag"], p["closest_match_table"], p["closest_match_name"])
          == ("fuzzy", "taxonomy_proposals", "Sanctions Screening") and p["similarity_score"] >= 85, p)
    ai.same_as = {"Anti Money Laundering Program Design": "AML/KYC Program Design"}
    propose("Anti Money Laundering Program Design", parent="Regulatory Compliance")
    p = by_name("Anti Money Laundering Program Design")
    check("synonym: an expansion of an existing skill's abbreviation",
          (p["auto_flag"], p["closest_match_table"], p["closest_match_name"]) == ("synonym", "sf_skills", "AML/KYC Program Design"), p)
    propose("Carbon Credit Trading", parent="Regulatory Compliance")
    check("none: a genuinely new term", by_name("Carbon Credit Trading")["auto_flag"] == "none")

    ai.answer = {"tags": [{"category": "Financial Services", "specialisation": "Regulatory Compliance", "confidence": 0.9,
                           "skills": ["Transaction Monitoring"]}], "new_terms": []}
    client.post("/suggest", json={"text": "Monitor transactions", "owner_type": "gig", "owner_id": 2, "user_id": 11})
    group = [p for p in pending() if p["proposed_name"] == "Transaction Monitoring"]
    check("the same term on two gigs: each lists the other as a duplicate",
          len(group) == 2 and group[0]["duplicates"] == [group[1]["id"]] and group[1]["duplicates"] == [group[0]["id"]], group)

    print("Approve (deliverable b)")
    r = client.post(f"/proposals/{group[0]['id']}/approve", json={"admin_user_id": 1}).json()
    tm = r["item"]["id"]
    check("both rows approved together", sorted(r["approved"]) == sorted(p["id"] for p in group), r)
    check("added to the taxonomy, linked under its specialisation",
          db.query("SELECT COUNT(*) AS n FROM sf_specialisation_skills WHERE specialisation_id = %s AND skill_id = %s",
                   (spec["Regulatory Compliance"], tm))[0]["n"] == 1)
    check("tagged on the gig that has the specialisation",
          db.query("SELECT COUNT(*) AS n FROM gig_skills WHERE gig_id = 1 AND skill_id = %s", (tm,))[0]["n"] == 1)
    check("skipped, with the reason, on the gig without it",
          [(s["owner_id"], s["reason"]) for s in r["skipped"]] == [(2, "the gig no longer has that specialisation")], r["skipped"])
    check("the rows leave the queue", all(p["proposed_name"] != "Transaction Monitoring" for p in pending()))
    r = client.post(f"/proposals/{group[0]['id']}/approve", json={})
    check("reviewing it again -> 409", r.status_code == 409, r.text)
    r = client.post("/lookup", json={"name": "Transaction Monitor", "kind": "skill", "parent_id": spec["Regulatory Compliance"]}).json()
    check("a variant under its specialisation now resolves to it", r.get("name") == "Transaction Monitoring", r)
    propose("Transaction Monitor", parent="Digital and Data Analytics")
    p = by_name("Transaction Monitor")
    check("under another specialisation it is flagged against the new taxonomy item",
          (p["auto_flag"], p["closest_match_table"], p["closest_match_id"]) == ("fuzzy", "sf_skills", tm), p)

    print("Reject (deliverable b)")
    p = by_name("Sustainable Finance")
    r = client.post(f"/proposals/{p['id']}/reject", json={"admin_user_id": 1, "reason": "Covered by Green Finance"}).json()
    row = db.query("SELECT status, deleted_at, admin_reason FROM taxonomy_proposals WHERE id = %s", (p["id"],))[0]
    check("soft-deleted: status rejected, deleted_at set, row kept", row["status"] == "rejected" and row["deleted_at"], row)
    check("detached from its gig", r["detached"] == [{"proposal_id": p["id"], "owner_type": "gig", "owner_id": 1}], r)
    note = db.query("SELECT user_id, message FROM user_notifications")
    check("the gig's owner is notified", len(note) == 1 and note[0]["user_id"] == 10 and "Covered by Green Finance" in note[0]["message"], note)
    check("gone from the gig's pending terms",
          all(x["proposed_name"] != "Sustainable Finance" for x in client.get("/proposals?owner_type=gig&owner_id=1").json()["proposals"]))
    p = by_name("Sanctions Screening")
    client.post(f"/proposals/{p['id']}/reject", json={})
    check("a term flagged against a rejected one is re-checked",
          by_name("Sanction Screening")["closest_match_table"] != "taxonomy_proposals", by_name("Sanction Screening"))

    print("Attach, approve a specialisation, token")
    ai.answer = {"tags": [], "new_terms": [{"kind": "specialisation", "name": "Forensic Accounting", "category": "Accountancy",
                                            "specialisation": None, "confidence": 0.7}]}
    r = client.post("/suggest", json={"text": "Fraud investigation", "owner_type": "showcase", "user_id": 20}).json()
    pid = r["pending"][0]["id"]
    r = client.post("/proposals/attach", json={"proposal_ids": [pid], "owner_type": "showcase", "owner_id": 1}).json()
    check("a proposal made before its showcase was saved is bound to it", r == {"attached": [pid]}, r)
    r = client.post(f"/proposals/{pid}/approve", json={}).json()
    check("an approved specialisation is added and tagged on the showcase",
          r["attached"] and db.query("SELECT COUNT(*) AS n FROM showcase_specialisations WHERE showcase_id = 1 AND specialisation_id = %s",
                                     (r["item"]["id"],))[0]["n"] == 1, r)
    db.execute("UPDATE taxonomy_proposals SET actioned_at = '2026-01-01 00:00:00' WHERE proposed_name = 'Forensic Accounting'")
    reviewed = [p["proposed_name"] for p in client.get("/proposals?status=approved").json()["proposals"]]
    check("reviewed terms: most recently reviewed first", reviewed[-1] == "Forensic Accounting" and len(reviewed) == 3, reviewed)
    service.SERVICE_TOKEN = "s3cret"
    check("with taxonomy.serviceToken set, a call without it -> 401", client.get("/health").status_code == 401)
    check("and with it -> 200", client.get("/health", headers={"X-Service-Token": "s3cret"}).status_code == 200)
    service.SERVICE_TOKEN = ""


def run_live(ranker: Path) -> None:
    """Real model, the whole sf_v1 taxonomy (from the Data team's records the ranker carries)."""
    records = []
    for name in ("providers.json", "hirers.json"):
        records += json.loads((ranker / "pipeline" / "data" / "data_set_v5_tax" / name).read_text(encoding="utf-8"))
    taxonomy: dict = {}
    for r in records:
        for c in (r.get("tags") or {}).get("categories", []):
            for s in c.get("specialisations", []):
                taxonomy.setdefault(c["name"], {}).setdefault(s["name"], set()).update(s.get("skills", []))
    service.db = SqliteDb()
    load(service.db, {c: {s: sorted(k) for s, k in specs.items()} for c, specs in taxonomy.items()})
    t = service.taxonomy()
    print(f"Live: {service.MODEL}, {len(t.categories)} categories, {len(t.specs)} specialisations, {len(t.skills)} skills, "
          f"prompt {len(t.prompt):,} chars")
    client = TestClient(service.app)
    for text in ("We need an expert to review our AML compliance framework against MAS Notice 626.",
                 "Help with finance stuff.",
                 "Our ESG team needs a consultant to build a carbon-credit trading desk and TCFD climate disclosures."):
        r = client.post("/suggest", json={"text": text, "owner_type": "gig"})
        print(f"\n  {text}\n  HTTP {r.status_code}")
        if r.status_code != 200:
            print("  ", r.text)
            continue
        for c in r.json()["tags"]:
            for s in c["specialisations"]:
                print(f"    {s['search_tag']}  conf {s['confidence']}: {', '.join(k['name'] for k in s['skills'])}")
        for p in client.get("/proposals").json()["proposals"]:
            print(f"    pending {p['kind']} {p['proposed_name']!r} under {p['specialisation'] or p['category']}, "
                  f"conf {p['confidence']}, flag {p['auto_flag']}"
                  + (f" ~ {p['closest_match_name']!r}" if p["closest_match_name"] else ""))
        service.db.execute("DELETE FROM taxonomy_proposals")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--live", action="store_true")
    parser.add_argument("--ranker", type=Path, default=Path(__file__).resolve().parents[2] / "ranker")
    args = parser.parse_args()
    if args.live:
        run_live(args.ranker)
    else:
        run_faked()
        print(f"\n{'All checks passed' if not check.failed else f'{check.failed} check(s) FAILED'}")
        sys.exit(1 if check.failed else 0)
