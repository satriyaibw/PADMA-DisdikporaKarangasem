#!/usr/bin/env python3
"""Terapkan filter Semester pada dashboard publik Metabase.

Kenapa ada script ini
---------------------
Perubahan kartu dan parameter dashboard publik dikerjakan langsung ke instance
Metabase, bukan ke file di repo. Instance baru (atau instance yang dibangun
ulang dari `metabase_questions.md`) karena itu tidak otomatis punya filter
semester. Script ini memutar ulang perubahan tersebut secara idempoten dari
payload `semester-filter.sql.json` di direktori yang sama.

Isi payload (rujukan: `metabase_questions.md` §2.3, §3.1, §3.4, §4.1, §6)
-----------------------------------------------------------------------
- 7 kartu ATS (82-88) + 4 kartu Sarana Prasarana (90, 91, 92, 95):
  SQL dengan field filter (`semester_ats`, `semester_ruang`, `semester_sanitasi`).
- 15 kartu dipin ke `semester_acuan` (14 kartu rombel + Q-PD-07).
- 2 parameter dashboard: `p-semester` (26 nilai) dan `p-semester-ats` (10 nilai),
  plus perbaikan urutan static list `p-status` menjadi `[nilai, label]`.
- `parameter_mappings` pada 11 dashcard terkait.

Pemakaian
---------
    python3 scripts/metabase/apply-semester-filter.py --dry-run
    python3 scripts/metabase/apply-semester-filter.py
    python3 scripts/metabase/apply-semester-filter.py --verify-only
    python3 scripts/metabase/apply-semester-filter.py --verify-only --url http://localhost:3000

Kredensial dibaca dari `dashboard-karangasem/.env` (`METABASE_ADMIN_EMAIL`,
`METABASE_ADMIN_PASSWORD`; URL dari `METABASE_URL`/`METABASE_INTERNAL_URL`).
State lama dicadangkan ke `scripts/metabase/.backup/<timestamp>/` sebelum menulis.
Exit code 0 = sukses/sesuai, 1 = ada yang gagal atau belum sesuai.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
ENV_FILE = REPO / "dashboard-karangasem" / ".env"
SCRIPT_DIR = Path(__file__).resolve().parent
PAYLOAD_FILE = SCRIPT_DIR / "semester-filter.sql.json"
BACKUP_ROOT = SCRIPT_DIR / ".backup"


def read_env(path: Path) -> dict[str, str]:
    data: dict[str, str] = {}
    if not path.exists():
        return data
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        data[key.strip()] = value.strip().strip('"').strip("'")
    return data


def api(base: str, path: str, session: str | None = None, body=None, method="GET"):
    payload = json.dumps(body).encode() if body is not None else None
    headers = {"Content-Type": "application/json"}
    if session:
        headers["X-Metabase-Session"] = session
    req = urllib.request.Request(base + path, data=payload, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            raw = resp.read()
            return json.loads(raw) if raw else None
    except urllib.error.HTTPError as exc:
        raise SystemExit(f"{method} {path} -> HTTP {exc.code}: {exc.read().decode()[:300]}")


def login(base: str, env: dict[str, str]) -> str:
    email = env.get("METABASE_ADMIN_EMAIL")
    password = env.get("METABASE_ADMIN_PASSWORD")
    if not email or not password:
        raise SystemExit("METABASE_ADMIN_EMAIL / METABASE_ADMIN_PASSWORD tidak ada di .env")
    res = api(base, "/api/session", body={"username": email, "password": password}, method="POST")
    return res["id"]


def connect(env: dict[str, str], override: str | None) -> tuple[str, str]:
    """Cari URL Metabase yang bisa dijangkau dari host maupun dari dalam Docker."""
    candidates = [c for c in (override, env.get("METABASE_URL"),
                              env.get("METABASE_INTERNAL_URL"), "http://localhost:3000") if c]
    errors = []
    for base in dict.fromkeys(c.rstrip("/") for c in candidates):
        try:
            return base, login(base, env)
        except SystemExit:
            raise
        except (urllib.error.URLError, OSError) as exc:
            errors.append(f"{base} ({exc})")
    raise SystemExit("Tidak bisa menjangkau Metabase. Dicoba: " + "; ".join(errors))


def normalise_sql(text: str) -> str:
    return "\n".join(line.rstrip() for line in text.replace("\r\n", "\n").splitlines()).strip()


def tag_names(native: dict) -> set[str]:
    return set((native.get("template-tags") or {}).keys())


REQUIRED_TAGS = {
    "semester_ats": "semester_ats",
    "semester_ruang": "semester_ruang",
    "semester_sanitasi": "semester_sanitasi",
}


def required_tag(card: dict) -> str | None:
    """Tag semester yang wajib ada pada kartu (None = kartu pin, cukup semester_acuan)."""
    tags = tag_names(card["dataset_query"]["native"])
    for name in REQUIRED_TAGS:
        if name in tags:
            return name
    return None


def find_card(base: str, session: str, card_id: str, name: str) -> dict:
    try:
        return api(base, f"/api/card/{card_id}", session=session)
    except SystemExit:
        pass
    # Instance hasil rebuild punya ID berbeda: cari berdasarkan nama (unik di collection).
    found = api(base, "/api/card", session=session) or []
    matches = [c for c in found if c.get("name") == name]
    if len(matches) != 1:
        raise SystemExit(f"Kartu {card_id} '{name}' tidak ditemukan (atau namanya duplikat)")
    return api(base, f"/api/card/{matches[0]['id']}", session=session)


def diff_card(current: dict, want: dict) -> bool:
    native = current["dataset_query"]["native"]
    if normalise_sql(native.get("query", "")) != normalise_sql(want["query"]):
        return True
    return tag_names(native) != set(want["template_tags"].keys())


def apply_cards(base: str, session: str, cards: dict, dry_run: bool) -> tuple[int, int]:
    updated = same = 0
    for card_id, want in cards.items():
        current = find_card(base, session, card_id, want["name"])
        if not diff_card(current, want):
            same += 1
            continue
        marker = " [dry-run]" if dry_run else ""
        print(f"  kartu {current['id']:>3} {want['name']}: SQL/tag diperbarui{marker}")
        if not dry_run:
            api(base, f"/api/card/{current['id']}", session=session, method="PUT",
                body={"dataset_query": {"database": 2, "type": "native",
                                        "native": {"query": want["query"],
                                                   "template-tags": want["template_tags"]}}})
        updated += 1
    return updated, same


def merge_parameters(current: list[dict], wanted: dict[str, dict]) -> tuple[list[dict], list[str]]:
    """Pertahankan urutan parameter yang ada, ganti/ tambahkan yang diinginkan."""
    result: list[dict] = []
    changed: list[str] = []
    seen: set[str] = set()
    for param in current:
        pid = param["id"]
        if pid in wanted:
            seen.add(pid)
            if json.dumps(param, sort_keys=True) != json.dumps(wanted[pid], sort_keys=True):
                result.append(wanted[pid])
                changed.append(f"{pid} diperbarui")
                continue
        result.append(param)
    for pid, param in wanted.items():
        if pid not in seen:
            result.append(param)
            changed.append(f"{pid} ditambahkan")
    return result, changed


def apply_dashboard(base: str, session: str, dash_id: int, wanted_params: dict[str, dict],
                    mappings: dict[str, dict], dry_run: bool) -> list[str]:
    dash = api(base, f"/api/dashboard/{dash_id}", session=session)
    params, changed = merge_parameters(dash.get("parameters") or [], wanted_params)

    dashcards = dash.get("dashcards") or []
    by_card: dict[int, list[dict]] = {}
    for dc in dashcards:
        by_card.setdefault(dc.get("card_id"), []).append(dc)

    mapping_changes = 0
    for entry in mappings.values():
        wanted = entry["parameter_mappings"]
        targets = by_card.get(entry["card_id"])
        if not targets:
            changed.append(f"dashcard untuk kartu {entry['card_id']} tidak ditemukan")
            continue
        for dc in targets:
            if json.dumps(dc.get("parameter_mappings") or [], sort_keys=True) != \
                    json.dumps(wanted, sort_keys=True):
                dc["parameter_mappings"] = wanted
                mapping_changes += 1
                changed.append(f"mapping kartu {entry['card_id']} diperbarui")

    for item in changed:
        print(f"  dashboard: {item}{' [dry-run]' if dry_run else ''}")

    if (params != (dash.get("parameters") or [])) or mapping_changes:
        if not dry_run:
            api(base, f"/api/dashboard/{dash_id}", session=session, method="PUT", body={
                "name": dash["name"],
                "description": dash.get("description"),
                "parameters": params,
                "tabs": dash.get("tabs") or [],
                "dashcards": dashcards,
            })
    return changed


def backup(base: str, session: str, dash_id: int, cards: dict, stamp: str) -> Path:
    target = BACKUP_ROOT / stamp
    target.mkdir(parents=True, exist_ok=True)
    dash = api(base, f"/api/dashboard/{dash_id}", session=session)
    (target / "dashboard.json").write_text(json.dumps(dash, indent=2, ensure_ascii=False),
                                           encoding="utf-8")
    for card_id, want in cards.items():
        try:
            current = find_card(base, session, card_id, want["name"])
        except SystemExit:
            continue
        (target / f"card-{current['id']}.json").write_text(
            json.dumps(current, indent=2, ensure_ascii=False), encoding="utf-8")
    return target


def verify(base: str, session: str, dash_id: int, payload: dict) -> list[str]:
    problems: list[str] = []
    cards = payload["cards"]
    dash = api(base, f"/api/dashboard/{dash_id}", session=session)

    for card_id, want in cards.items():
        try:
            current = find_card(base, session, card_id, want["name"])
        except SystemExit as exc:
            problems.append(str(exc))
            continue
        if diff_card(current, want):
            problems.append(f"kartu {current['id']} '{want['name']}': SQL/tag belum sesuai")
        tag = required_tag(current)
        if tag is None and "semester_acuan" not in normalise_sql(current["dataset_query"]["native"].get("query", "")):
            problems.append(f"kartu {current['id']} '{want['name']}': tidak punya tag semester "
                            f"maupun blok semester_acuan")

    params = {p["id"]: p for p in dash.get("parameters") or []}
    for pid, want in payload["dashboard_parameters"].items():
        have = params.get(pid)
        if have is None:
            problems.append(f"parameter {pid} tidak ada di dashboard")
            continue
        want_values = (want.get("values_source_config") or {}).get("values") or []
        have_values = (have.get("values_source_config") or {}).get("values") or []
        if len(have_values) != len(want_values):
            problems.append(f"parameter {pid}: {len(have_values)} nilai, seharusnya {len(want_values)}")
        elif want_values and have_values[:1] != want_values[:1]:
            problems.append(f"parameter {pid}: urutan static list salah "
                            f"(harus [nilai, label], contoh {want_values[0]})")

    by_card: dict[int, list[dict]] = {}
    for dc in dash.get("dashcards") or []:
        by_card.setdefault(dc.get("card_id"), []).append(dc)
    for entry in payload["dashcard_parameter_mappings"].values():
        targets = by_card.get(entry["card_id"]) or []
        if not targets:
            problems.append(f"dashcard untuk kartu {entry['card_id']} tidak ada")
            continue
        for dc in targets:
            have = sorted((m["parameter_id"], json.dumps(m["target"]))
                          for m in dc.get("parameter_mappings") or [])
            want = sorted((m["parameter_id"], json.dumps(m["target"]))
                          for m in entry["parameter_mappings"])
            if have != want:
                problems.append(f"mapping dashcard kartu {entry['card_id']}: belum sesuai")
    return problems


def verify_queries(base: str, dash: dict) -> list[str]:
    """Jalankan semua dashcard lewat endpoint publik dengan parameter kosong."""
    uuid = dash.get("public_uuid")
    if not uuid:
        print("  (lewati uji kueri: dashboard tidak punya public_uuid)")
        return []
    ptype = {p["id"]: p["type"] for p in dash.get("parameters") or []}
    problems: list[str] = []
    total = 0
    for dc in sorted(dash.get("dashcards") or [], key=lambda d: d["id"]):
        params = [{"type": ptype.get(m["parameter_id"], "string/="), "value": None,
                   "id": m["parameter_id"], "target": m["target"]}
                  for m in dc.get("parameter_mappings") or []]
        query = "?" + urllib.parse.urlencode({"parameters": json.dumps(params)})
        url = f"{base}/api/public/dashboard/{uuid}/dashcard/{dc['id']}/card/{dc['card_id']}{query}"
        try:
            with urllib.request.urlopen(url, timeout=180) as resp:
                res = json.loads(resp.read())
        except urllib.error.HTTPError as exc:
            problems.append(f"dashcard {dc['id']} (kartu {dc['card_id']}): HTTP {exc.code}")
            continue
        total += 1
        if res.get("status") != "completed":
            problems.append(f"dashcard {dc['id']} (kartu {dc['card_id']}): status "
                            f"{res.get('status')} — {res.get('error')}")
    print(f"  {total} dashcard dieksekusi")
    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dry-run", action="store_true", help="tampilkan rencana tanpa menulis")
    parser.add_argument("--verify-only", action="store_true", help="hanya periksa, tidak menulis")
    parser.add_argument("--skip-query-check", action="store_true",
                        help="lewati uji kueri 46 dashcard lewat endpoint publik")
    parser.add_argument("--url", help="URL Metabase (mis. http://localhost:3000)")
    args = parser.parse_args()

    if not PAYLOAD_FILE.exists():
        raise SystemExit(f"Payload tidak ditemukan: {PAYLOAD_FILE}")
    payload = json.loads(PAYLOAD_FILE.read_text(encoding="utf-8"))
    dash_id = int(payload["dashboard_id"])

    env = read_env(ENV_FILE)
    base, session = connect(env, args.url or os.environ.get("METABASE_URL"))
    print(f"Metabase: {base} — dashboard {dash_id}")

    if args.verify_only:
        print("\nVerifikasi struktur:")
        problems = verify(base, session, dash_id, payload)
        if not args.skip_query_check:
            print("Verifikasi kueri:")
            problems += verify_queries(base, api(base, f"/api/dashboard/{dash_id}", session=session))
        if problems:
            print("\nBELUM SESUAI:")
            for item in problems:
                print(" -", item)
            return 1
        print("\nOK: filter semester sudah terpasang dan semua kartu berjalan.")
        return 0

    if args.dry_run:
        print("Backup: dilewati (dry-run)")
    else:
        stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
        target = backup(base, session, dash_id, payload["cards"], stamp)
        print(f"Backup: {target.relative_to(REPO)}")

    print("\nKartu:")
    updated, same = apply_cards(base, session, payload["cards"], args.dry_run)
    print(f"  {updated} diperbarui, {same} sudah sesuai")

    print("\nDashboard:")
    changes = apply_dashboard(base, session, dash_id, payload["dashboard_parameters"],
                              payload["dashcard_parameter_mappings"], args.dry_run)
    if not changes:
        print("  parameter & mapping sudah sesuai")

    if args.dry_run:
        print("\nDry-run selesai, tidak ada perubahan ditulis.")
        return 0

    print("\nVerifikasi:")
    problems = verify(base, session, dash_id, payload)
    if not args.skip_query_check:
        problems += verify_queries(base, api(base, f"/api/dashboard/{dash_id}", session=session))
    if problems:
        print("\nGAGAL:")
        for item in problems:
            print(" -", item)
        print(f"\nRollback: pulihkan dari {target.relative_to(REPO)}")
        return 1
    print("\nOK: filter semester terpasang, semua kartu berjalan tanpa error.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
