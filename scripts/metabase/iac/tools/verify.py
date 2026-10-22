#!/usr/bin/env python3
"""Verifikasi instance Metabase terhadap spec YAML.

    python3 tools/verify.py                     # pemeriksaan penuh
    python3 tools/verify.py --skip-query-check  # hanya struktur & tata letak
    python3 tools/verify.py --url http://localhost:3000

Yang diperiksa:
1. struktur: 6 tab, kartu di tiap tab, parameter, mapping parameter,
2. kartu: nama/display/SQL/tag/dimension sama dengan spec,
3. tata letak: posisi tiap dashcard dan tidak ada sel bertumpuk,
4. eksekusi: 46 dashcard dijalankan lewat endpoint publik (harus `completed`),
5. angka: nilai scalar / jumlah baris dibandingkan `expect` di YAML.

Exit 0 = sesuai. Exit 1 = ada drift. Exit 2 = tidak bisa memeriksa
(mis. dashboard belum ada / public link mati).
"""
from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

import desired
import resolve_cards
import spec
from mb_client import MetabaseError, connect, say
from resolver import ResolveError, Resolver
from spec import DashboardSpec


def value_matches(actual: Any, expected: Any) -> bool:
    if actual is None or expected is None:
        return actual == expected
    if isinstance(actual, (int, float)) and isinstance(expected, (int, float)):
        return abs(float(actual) - float(expected)) <= max(0.02, abs(float(expected)) * 0.001)
    return str(actual) == str(expected)


def check_structure(dashboard: DashboardSpec, resolver: Resolver,
                    card_ids: dict[str, int], live: dict[str, Any]) -> list[str]:
    problems: list[str] = []
    tabs = sorted(live.get("tabs") or [], key=lambda item: item.get("position", 0))
    names = [tab["name"] for tab in tabs]
    expected = [tab.name for tab in dashboard.tabs]
    if names != expected:
        problems.append(f"urutan/nama tab: instance={names} spec={expected}")

    tab_id = {tab["name"]: tab["id"] for tab in tabs}
    slots: dict[str, int] = {tab.name: 0 for tab in dashboard.tabs}
    dashcards: dict[tuple[str, int], dict[str, Any]] = {}
    for dashcard in live.get("dashcards") or []:
        tab_name = next(
            (name for name, identifier in tab_id.items() if identifier == dashcard.get("dashboard_tab_id")),
            None,
        )
        if tab_name in slots:
            slots[tab_name] += 1
        dashcards[(tab_name or "?", int(dashcard["card_id"]))] = dashcard

    for tab in dashboard.tabs:
        wanted = len(dashboard.cards_for_tab(tab.key))
        if slots.get(tab.name, 0) != wanted:
            problems.append(f"tab {tab.name}: {slots.get(tab.name, 0)} kartu, seharusnya {wanted}")

    grid_problems = spec.layout_problems(dashboard.cards)
    problems.extend(grid_problems)

    for card in dashboard.analytic_cards:
        card_id = card_ids.get(card.key)
        dashcard = dashcards.get((dashboard.tabs[[t.key for t in dashboard.tabs].index(card.tab)].name, card_id)) if card_id else None
        if dashcard is None:
            problems.append(f"{card.key}: tidak ada di dashboard (tab {card.tab})")
            continue
        grid = card.layout
        assert grid is not None
        actual = (dashcard.get("row"), dashcard.get("col"), dashcard.get("size_x"), dashcard.get("size_y"))
        if actual != (grid.row, grid.col, grid.w, grid.h):
            problems.append(f"{card.key}: layout instance={actual} spec={(grid.row, grid.col, grid.w, grid.h)}")
        mapping_problems = desired.mapping_problems(
            dashcard.get("parameter_mappings") or [],
            desired.mappings_for(card, card_id),
        )
        problems.extend(f"{card.key}: {item}" for item in mapping_problems)

    return problems


def check_parameters(dashboard: DashboardSpec, resolver: Resolver,
                     helper_ids: dict[str, int], live: dict[str, Any]) -> list[str]:
    problems: list[str] = []
    current = {param["id"]: param for param in live.get("parameters") or []}
    for param in dashboard.parameters:
        have = current.get(param.key)
        if have is None:
            problems.append(f"parameter {param.key} tidak ada di dashboard")
            continue
        wanted = desired.parameter_payload(param, helper_ids.get(param.from_card or ""))
        problems.extend(
            f"parameter {param.key}: {item}"
            for item in desired.parameter_problems(have, wanted)
        )
    extra = sorted(set(current) - {param.key for param in dashboard.parameters})
    if extra:
        problems.append(f"parameter di luar spec: {extra}")
    return problems


def check_cards(dashboard: DashboardSpec, resolver: Resolver,
                card_ids: dict[str, int]) -> list[str]:
    problems: list[str] = []
    database_id = resolver.database_id
    for card in dashboard.cards:
        card_id = card_ids.get(card.key)
        if not card_id:
            problems.append(f"{card.key}: kartu tidak ditemukan di collection '{card.collection}'")
            continue
        collection_id = resolver.collection_id(card.collection)
        current = resolver.mb.get(f"/api/card/{card_id}")
        payload = desired.card_payload(card, resolver, database_id, collection_id)
        problems.extend(f"{card.key} (id {card_id}): {item}" for item in desired.card_problems(current, payload))
    return problems


def public_uuid(resolver: Resolver, live: dict[str, Any]) -> str | None:
    return live.get("public_uuid")


def run_dashcards(dashboard: DashboardSpec, resolver: Resolver, live: dict[str, Any],
                  card_ids: dict[str, int]) -> tuple[list[str], list[str]]:
    """Jalankan tiap dashcard lewat endpoint publik (tanpa login)."""
    problems: list[str] = []
    notes: list[str] = []
    uuid = public_uuid(resolver, live)
    if not uuid:
        return ["dashboard belum punya public_uuid; jalankan apply atau aktifkan public sharing"], notes
    ptype = {param["id"]: param["type"] for param in live.get("parameters") or []}
    card_by_id = {int(dashcard["card_id"]): dashcard for dashcard in live.get("dashcards") or []}
    order = {card.key: index for index, card in enumerate(dashboard.analytic_cards)}

    checked = 0
    for card in dashboard.analytic_cards:
        card_id = card_ids.get(card.key)
        dashcard = card_by_id.get(card_id) if card_id else None
        if not dashcard:
            continue
        params = [
            {
                "type": ptype.get(mapping["parameter_id"], "string/="),
                "value": None,
                "id": mapping["parameter_id"],
                "target": mapping["target"],
            }
            for mapping in dashcard.get("parameter_mappings") or []
        ]
        query = "?" + urllib.parse.urlencode({"parameters": json.dumps(params)})
        url = (
            f"{resolver.mb.base}/api/public/dashboard/{uuid}"
            f"/dashcard/{dashcard['id']}/card/{dashcard['card_id']}{query}"
        )
        try:
            with urllib.request.urlopen(url, timeout=300) as response:
                result = json.loads(response.read())
        except urllib.error.HTTPError as exc:
            problems.append(f"{card.key} (dashcard {dashcard['id']}): HTTP {exc.code}")
            continue
        except (urllib.error.URLError, TimeoutError) as exc:
            problems.append(f"{card.key}: tidak bisa dihubungi ({exc})")
            continue

        checked += 1
        if result.get("status") != "completed":
            problems.append(
                f"{card.key} (dashcard {dashcard['id']}): status {result.get('status')} "
                f"— {str(result.get('error'))[:200]}"
            )
            continue
        rows = (result.get("data") or {}).get("rows") or []
        if "value" in card.expect:
            actual = rows[0][0] if rows and rows[0] else None
            if not value_matches(actual, card.expect["value"]):
                problems.append(
                    f"{card.key}: nilai {actual!r}, ekspektasi {card.expect['value']!r} "
                    "(data sumber berubah? perbarui expect bila memang benar)"
                )
        elif "rows" in card.expect:
            if len(rows) != card.expect["rows"]:
                problems.append(f"{card.key}: {len(rows)} baris, ekspektasi {card.expect['rows']}")
        notes.append(f"{card.key}: {len(rows)} baris")
    if checked == 0:
        problems.append("tidak ada dashcard yang bisa dijalankan")
    notes.sort(key=lambda item: order.get(item.split(":")[0], 999))
    return problems, notes


def run_helper_cards(dashboard: DashboardSpec, resolver: Resolver,
                     card_ids: dict[str, int]) -> list[str]:
    problems: list[str] = []
    for card in dashboard.helper_cards:
        card_id = card_ids.get(card.key)
        if not card_id:
            problems.append(f"{card.key}: kartu bantu tidak ditemukan")
            continue
        result = resolver.mb.post(f"/api/card/{card_id}/query", {"parameters": []})
        if (result or {}).get("status") != "completed":
            problems.append(f"{card.key}: status {(result or {}).get('status')} — {result.get('error')}")
            continue
        rows = ((result or {}).get("data") or {}).get("rows") or []
        if "rows" in card.expect and len(rows) != card.expect["rows"]:
            problems.append(
                f"{card.key}: {len(rows)} opsi dropdown, ekspektasi {card.expect['rows']}"
            )
        elif "value" in card.expect and rows:
            if not value_matches(rows[0][0], card.expect["value"]):
                problems.append(f"{card.key}: nilai {rows[0][0]!r}, ekspektasi {card.expect['value']!r}")
    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--skip-query-check", action="store_true",
                        help="lewati eksekusi 46 dashcard + cek angka")
    parser.add_argument("--url", help="URL Metabase (default: deteksi otomatis)")
    parser.add_argument("--env-file", help="file .env berisi kredensial Metabase")
    parser.add_argument("--iac-dir", help="direktori spec (default: induk tools/)")
    args = parser.parse_args()

    iac_dir = Path(args.iac_dir).resolve() if args.iac_dir else Path(__file__).resolve().parents[1]
    try:
        dashboard = spec.load(iac_dir)
    except Exception as exc:
        say(f"GAGAL membaca spec: {exc}")
        return 2

    mb, base, env_path = connect(args.url, args.env_file)
    say(f"Metabase: {base}" + (f" (kredensial: {env_path})" if env_path else ""))
    resolver = Resolver(mb, dashboard.database)

    try:
        found = resolver.find_dashboard(dashboard.name)
        if not found:
            say(f"GAGAL: dashboard '{dashboard.name}' tidak ada. Jalankan `python3 tools/apply.py` dulu.")
            return 2
        live = resolver.dashboard(int(found["id"]))

        card_ids, notes = resolve_cards.resolve_card_ids(dashboard, resolver, live)
        for note in notes:
            say(f"  catatan: {note}")
    except ResolveError as exc:
        say(f"GAGAL: {exc}")
        return 2
    except MetabaseError as exc:
        say(f"GAGAL memanggil Metabase: {exc}")
        return 2

    helper_ids = {card.key: card_ids[card.key] for card in dashboard.helper_cards if card.key in card_ids}
    problems: list[str] = []
    problems += check_structure(dashboard, resolver, card_ids, live)
    problems += check_parameters(dashboard, resolver, helper_ids, live)
    problems += check_cards(dashboard, resolver, card_ids)

    if not args.skip_query_check:
        query_problems, notes = run_dashcards(dashboard, resolver, live, card_ids)
        problems += query_problems
        problems += run_helper_cards(dashboard, resolver, card_ids)
        print(f"Kueri dijalankan: {len(notes)} dashcard + {len(dashboard.helper_cards)} kartu bantu")

    print(
        f"Struktur: {len(dashboard.tabs)} tab, {len(dashboard.analytic_cards)} kartu, "
        f"{len(dashboard.parameters)} parameter, public_uuid={live.get('public_uuid')}"
    )
    if problems:
        print(f"\nBELUM SESUAI ({len(problems)}):")
        for problem in problems:
            print(f"  - {problem}")
        return 1
    print("\nOK: instance sesuai spec, semua kartu berjalan dan angka cocok.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
