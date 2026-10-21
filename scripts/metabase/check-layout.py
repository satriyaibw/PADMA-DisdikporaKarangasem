#!/usr/bin/env python3
"""Cek tata letak dashcard Metabase: sel grid tidak boleh bertumpuk.

Kenapa penting
--------------
Metabase menggeser kartu yang sel grid-nya bertabrakan ke bawah saat render,
tetapi tinggi dashboard (posisi footer "Didukung oleh Metabase") dihitung dari
`row` deklaratif. Bila ada tabrakan, konten menjadi lebih panjang dari wadah
dan kartu terakhir menembus footer.

Contoh nyata (tab Sarana Prasarana, 21 Okt 2026): SAR-07 dipasang di
`row 12 col 12` padahal SAR-04 sudah menempati `row 8-16 col 12`. Kartu
terdorong ke `row 16` dan kartu berikutnya ke `row 24`, sementara footer tetap
di `row 30` sehingga tertimpa 162 px.

Pemakaian
---------
    python3 scripts/metabase/check-layout.py            # dashboard publik (id 2)
    python3 scripts/metabase/check-layout.py --dashboard 3

Kredensial dibaca dari dashboard-karangasem/.env
(METABASE_INTERNAL_URL, METABASE_ADMIN_EMAIL, METABASE_ADMIN_PASSWORD).
Exit code 0 = aman, 1 = ada tabrakan/masalah.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
ENV_FILE = REPO / "dashboard-karangasem" / ".env"
GRID_COLUMNS = 24


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
    """Cari URL Metabase yang bisa dijangkau dari host maupun dari dalam Docker.

    `METABASE_INTERNAL_URL` berisi `http://metabase:3000` yang hanya resolve di
    dalam jaringan Docker, jadi dari host perlu fallback ke localhost:3000.
    """
    candidates = [c for c in (override, env.get("METABASE_URL"),
                              env.get("METABASE_INTERNAL_URL"), "http://localhost:3000") if c]
    errors = []
    for base in dict.fromkeys(c.rstrip("/") for c in candidates):
        try:
            return base, login(base, env)
        except SystemExit as exc:  # HTTP error (kredensial salah, dll.)
            raise
        except (urllib.error.URLError, OSError) as exc:
            errors.append(f"{base} ({exc})")
    raise SystemExit("Tidak bisa menjangkau Metabase. Dicoba: " + "; ".join(errors))


def check(dashboard: dict) -> list[str]:
    problems: list[str] = []
    tabs = {t["id"]: t["name"] for t in dashboard.get("tabs") or []}
    per_tab: dict[int, list[dict]] = {}
    for dc in dashboard.get("dashcards") or []:
        per_tab.setdefault(dc.get("dashboard_tab_id"), []).append(dc)

    for tab_id, cards in per_tab.items():
        name = tabs.get(tab_id, f"tanpa tab ({tab_id})")
        if tab_id not in tabs:
            problems.append(f"{name}: ada dashcard yang tidak punya tab")
        for dc in cards:
            if dc["col"] + dc["size_x"] > GRID_COLUMNS:
                problems.append(
                    f"{name}: '{dc['card']['name']}' melewati 24 kolom "
                    f"(col {dc['col']} + {dc['size_x']})")
        for i in range(len(cards)):
            for j in range(i + 1, len(cards)):
                a, b = cards[i], cards[j]
                if (a["row"] < b["row"] + b["size_y"] and b["row"] < a["row"] + a["size_y"]
                        and a["col"] < b["col"] + b["size_x"] and b["col"] < a["col"] + a["size_x"]):
                    problems.append(
                        f"{name}: '{a['card']['name']}' (row {a['row']}-{a['row'] + a['size_y']}, "
                        f"col {a['col']}-{a['col'] + a['size_x']}) bertumpuk dengan "
                        f"'{b['card']['name']}' (row {b['row']}-{b['row'] + b['size_y']}, "
                        f"col {b['col']}-{b['col'] + b['size_x']})")
        bottom = max(dc["row"] + dc["size_y"] for dc in cards)
        print(f"tab {name}: {len(cards)} kartu, baris 0-{bottom}")
    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dashboard", type=int, default=2, help="ID dashboard (default 2 = publik)")
    parser.add_argument("--url", help="URL Metabase (mis. http://localhost:3000)")
    args = parser.parse_args()

    env = read_env(ENV_FILE)
    base, session = connect(env, args.url or os.environ.get("METABASE_URL"))
    print(f"Metabase: {base}")
    dashboard = api(base, f"/api/dashboard/{args.dashboard}", session=session)

    print(f"Dashboard {args.dashboard} — {dashboard['name']}")
    problems = check(dashboard)
    if problems:
        print("\nTATA LETAK BERMASALAH:")
        for item in problems:
            print(" -", item)
        print("\nPerbaiki `row`/`col` agar tidak ada sel bertumpuk, lalu jalankan ulang.")
        return 1
    print("\nOK: tidak ada sel grid bertumpuk.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
