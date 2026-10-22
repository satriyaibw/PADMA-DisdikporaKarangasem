#!/usr/bin/env python3
"""Export instance Metabase -> YAML "card as code".

Arah sebaliknya dari `apply.py`. Dipakai sekali saat membangun spec dari
instance yang sudah jadi, dan sesudahnya untuk memeriksa drift:

    python3 tools/export.py --check     # gagal (exit 1) bila YAML != instance
    python3 tools/export.py             # tulis ulang YAML dari instance

Yang diekspor: metadata dashboard, 6 tab, 9 parameter, 46 kartu analitik
(SQL + tag + tata letak + ekspektasi), dan 6 kartu bantu dropdown.

Nilai `expect` diambil dari hasil kueri saat export (baseline regresi), karena
itu export butuh instance yang sehat. Angka pada `metabase_questions.md`
tetap menjadi rujukan manusia; `verify.py` membandingkan hasil nyata dengannya.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path
from typing import Any

import spec
from mb_client import connect, say
from resolver import ResolveError, Resolver


# ---------------------------------------------------------------------------
# Key map Q-XXX-NN dari dokumentasi (opsional, agar kunci kartu stabil)
# ---------------------------------------------------------------------------
INVENTORY_ROW = re.compile(r"^\|\s*(Q-[A-Z]+-\d+)\s*\|\s*`(\d+)`", re.MULTILINE)


def read_key_map(path: Path | None) -> dict[int, str]:
    if not path or not path.is_file():
        return {}
    return {int(card_id): key for key, card_id in INVENTORY_ROW.findall(path.read_text(encoding="utf-8"))}


def default_key_map_path() -> Path | None:
    """`<repo>/docs/metabase-ids.md` bila tool dijalankan dari dalam repo."""
    candidate = Path(__file__).resolve().parents[4] / "docs" / "metabase-ids.md"
    return candidate if candidate.is_file() else None


# ---------------------------------------------------------------------------
# Ekstraksi spec dari instance
# ---------------------------------------------------------------------------
def card_expectation(mb, card_id: int, display: str) -> dict[str, Any]:
    result = mb.post(f"/api/card/{card_id}/query", {"parameters": []})
    data = (result or {}).get("data") or {}
    rows = data.get("rows") or []
    if display in {"scalar", "smartscalar"}:
        value = rows[0][0] if rows and rows[0] else None
        return {"value": value}
    return {"rows": len(rows)}


def card_filters(card: dict[str, Any], resolver: Resolver, parameter_of_tag: dict[str, str],
                 parameter_label: dict[str, str]) -> list[dict[str, Any]]:
    tags = (card["dataset_query"]["native"].get("template-tags") or {})
    filters: list[dict[str, Any]] = []
    for tag_name in sorted(tags):
        tag = tags[tag_name]
        if tag.get("type") != "dimension":
            raise ResolveError(
                f"Kartu '{card['name']}': tag '{tag_name}' bertipe {tag.get('type')!r}, "
                "bukan dimension. Spec hanya mendukung field filter."
            )
        dimension = tag.get("dimension") or []
        field_id = dimension[1] if len(dimension) > 1 else None
        if not isinstance(field_id, int):
            raise ResolveError(
                f"Kartu '{card['name']}': tag '{tag_name}' tidak memakai field id numerik "
                f"({dimension!r}); tidak bisa dipetakan ke {{schema, table, column}}."
            )
        meta = resolver.field_by_id(field_id)
        filters.append(
            spec.FlowMap(
                tag=tag_name,
                parameter=parameter_of_tag.get(tag_name, ""),
                label=tag.get("display-name") or parameter_label.get(tag_name) or tag_name,
                field=spec.FlowMap(
                    schema=meta["table_schema"], table=meta["table_name"], column=meta["name"]
                ),
            )
        )
    return filters


def collection_name(card: dict[str, Any], resolver: Resolver) -> str:
    """Nama collection dari id kartu (bukan dari objek `collection` yang bisa null)."""
    collection_id = card.get("collection_id")
    for name, identifier in resolver.collections().items():
        if identifier == collection_id:
            return name
    nested = card.get("collection")
    if isinstance(nested, dict) and nested.get("name"):
        return str(nested["name"])
    raise ResolveError(
        f"Kartu '{card['name']}' tidak punya collection yang bisa dikenali "
        f"(collection_id={collection_id})."
    )


def layout_of(dashcard: dict[str, Any]) -> spec.FlowMap:
    return spec.FlowMap(
        row=int(dashcard["row"]),
        col=int(dashcard["col"]),
        w=int(dashcard["size_x"]),
        h=int(dashcard["size_y"]),
    )


def build_documents(mb, resolver: Resolver, dashboard: dict[str, Any], key_map: dict[int, str]):
    tabs = sorted(dashboard.get("tabs") or [], key=lambda item: item.get("position", 0))
    tab_key = {tab["id"]: spec.slug(tab["name"]) for tab in tabs}
    tab_name = {tab["id"]: tab["name"] for tab in tabs}

    # Parameter dashboard -> sumber nilai & label per tag
    parameters: list[spec.ParamSpec] = []
    helper_keys: dict[int, str] = {}
    source_cards: dict[int, dict[str, Any]] = {}
    parameter_label = {param["id"]: param["name"] for param in dashboard.get("parameters") or []}

    for param in dashboard.get("parameters") or []:
        source_type = param.get("values_source_type")
        config = param.get("values_source_config") or {}
        if source_type == "card":
            card_id = int(config["card_id"])
            helper_key = "filter-" + param["id"].removeprefix("p-")
            helper_keys[card_id] = helper_key
            value_field = config.get("value_field") or []
            parameters.append(
                spec.ParamSpec(
                    key=param["id"],
                    name=param["name"],
                    slug=param["slug"],
                    type=param["type"],
                    multi=bool(param.get("isMultiSelect", True)),
                    from_card=helper_key,
                    value_field=str(value_field[1]) if len(value_field) > 1 else "",
                )
            )
        elif source_type == "static-list":
            parameters.append(
                spec.ParamSpec(
                    key=param["id"],
                    name=param["name"],
                    slug=param["slug"],
                    type=param["type"],
                    multi=bool(param.get("isMultiSelect", True)),
                    static_list=tuple((item[0], str(item[1])) for item in config.get("values") or []),
                )
            )
        else:
            raise ResolveError(
                f"Parameter '{param['id']}' memakai values_source_type={source_type!r}; "
                "spec hanya mendukung 'card' dan 'static-list'."
            )

    # Tag -> parameter dari parameter_mappings tiap dashcard
    dashcards = sorted(
        dashboard.get("dashcards") or [],
        key=lambda item: (tab_key.get(item.get("dashboard_tab_id"), ""), item["row"], item["col"]),
    )
    tag_to_parameter: dict[int, dict[str, str]] = {}
    for dashcard in dashcards:
        mapping: dict[str, str] = {}
        for entry in dashcard.get("parameter_mappings") or []:
            target = entry.get("target") or []
            if len(target) > 1 and target[0] == "dimension" and target[1][0] == "template-tag":
                mapping[target[1][1]] = entry["parameter_id"]
        tag_to_parameter[int(dashcard["card_id"])] = mapping

    cards_by_tab: dict[str, list[dict[str, Any]]] = {}
    for dashcard in dashcards:
        card = mb.get(f"/api/card/{dashcard['card_id']}")
        key = key_map.get(int(card["id"])) or f"{tab_key[dashcard['dashboard_tab_id']]}-{len(cards_by_tab.get(tab_key[dashcard['dashboard_tab_id']], [])) + 1:02d}"
        entry: dict[str, Any] = {
            "key": key,
            "name": card["name"],
            "tab": tab_key[dashcard["dashboard_tab_id"]],
            "collection": collection_name(card, resolver),
            "display": card["display"],
            "description": card.get("description") or "",
            "layout": layout_of(dashcard),
            "filters": card_filters(
                card,
                resolver,
                tag_to_parameter.get(int(card["id"]), {}),
                parameter_label,
            ),
            "expect": card_expectation(mb, int(card["id"]), card["display"]),
            "visualization_settings": card.get("visualization_settings") or {},
        }
        entry["sql"] = card["dataset_query"]["native"]["query"]
        cards_by_tab.setdefault(tab_key[dashcard["dashboard_tab_id"]], []).append(entry)

    # Kartu bantu (pemasok dropdown) + nama collection-nya
    helper_entries: list[dict[str, Any]] = []
    for card_id, helper_key in helper_keys.items():
        card = mb.get(f"/api/card/{card_id}")
        source_cards[card_id] = card
        helper_entries.append(
            {
                "key": helper_key,
                "name": card["name"],
                "collection": collection_name(card, resolver),
                "display": card["display"],
                "description": card.get("description") or "",
                "expect": card_expectation(mb, card_id, card["display"]),
                "sql": spec.Literal(spec.normalise_sql(card["dataset_query"]["native"]["query"])),
            }
        )
    helper_entries.sort(key=lambda item: item["key"])

    dashboard_doc = {
        "dashboard": {
            "database": mb.get(f"/api/database/{resolver.database_id}")["name"],
            "name": dashboard["name"],
            "description": dashboard.get("description") or "",
            "width": dashboard.get("width") or "fixed",
            "auto_apply_filters": bool(dashboard.get("auto_apply_filters", True)),
            "public_link": bool(dashboard.get("public_uuid")),
        },
        "tabs": [{"key": tab_key[tab["id"]], "name": tab["name"]} for tab in tabs],
        "parameters": [
            {
                "key": param.key,
                "name": param.name,
                "slug": param.slug,
                "type": param.type,
                "multi": param.multi,
                "values": (
                    {"from_card": param.from_card, "value_field": param.value_field}
                    if param.from_card
                    else {"static_list": [spec.FlowList(item) for item in param.static_list]}
                ),
            }
            for param in parameters
        ],
    }
    return dashboard_doc, cards_by_tab, helper_entries


# ---------------------------------------------------------------------------
# Tulis YAML
# ---------------------------------------------------------------------------
COMMON_HEADER = """\
# GENERATED oleh tools/export.py -- jangan diedit tangan tanpa menjalankan
# `python3 tools/validate.py` sesudahnya.
#
# Sumber kebenaran dashboard publik Disdikpora Karangasem. Semua rujukan
# memakai NAMA (database, collection, kartu) dan {schema, table, column},
# bukan id numerik Metabase, supaya bisa diterapkan di instance lain.
"""

DASHBOARD_HEADER = COMMON_HEADER + """#
# Menerapkan: python3 tools/apply.py --dry-run  lalu  python3 tools/apply.py
"""


def card_document(entries: list[dict[str, Any]], header: str) -> str:
    normalized: list[dict[str, Any]] = []
    for entry in entries:
        item = dict(entry)
        item["sql"] = spec.Literal(spec.normalise_sql(item["sql"]))
        normalized.append(item)
    body = spec.dump({"cards": normalized})
    return header + "\n" + body


def write_if_changed(path: Path, content: str) -> bool:
    if path.exists() and path.read_text(encoding="utf-8") == content:
        return False
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")
    return True


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--check", action="store_true",
                        help="hanya bandingkan YAML dengan instance (exit 1 bila beda)")
    parser.add_argument("--url", help="URL Metabase (default: deteksi otomatis)")
    parser.add_argument("--env-file", help="file .env berisi kredensial Metabase")
    parser.add_argument("--dashboard", type=int, default=2,
                        help="id dashboard publik di instance ini (default 2)")
    parser.add_argument("--key-map", help="file inventaris (docs/metabase-ids.md) untuk kunci Q-XXX-NN")
    args = parser.parse_args()

    iac_dir = Path(__file__).resolve().parents[1]
    mb, base, env_path = connect(args.url, args.env_file)
    say(f"Metabase: {base}" + (f" (kredensial: {env_path})" if env_path else ""))

    dashboard = mb.get(f"/api/dashboard/{args.dashboard}")
    database_name = mb.get(f"/api/database/{dashboard['dashcards'][0]['card']['database_id']}")["name"]
    resolver = Resolver(mb, database_name)

    key_map = read_key_map(Path(args.key_map) if args.key_map else default_key_map_path())
    dashboard_doc, cards_by_tab, helper_entries = build_documents(mb, resolver, dashboard, key_map)

    outputs: dict[Path, str] = {
        iac_dir / "dashboard.public.yaml": DASHBOARD_HEADER + "\n" + spec.dump(dashboard_doc),
        iac_dir / "cards" / "filters.yaml": card_document(
            helper_entries,
            COMMON_HEADER + "#\n# Kartu bantu: pemasok opsi dropdown filter, tidak tampil di dashboard.\n",
        ),
    }
    for tab in dashboard_doc["tabs"]:
        entries = cards_by_tab.get(tab["key"], [])
        if not entries:
            continue
        outputs[iac_dir / "cards" / f"{tab['key']}.yaml"] = card_document(
            entries, COMMON_HEADER + f"# Tab: {tab['name']} ({len(entries)} kartu)\n"
        )

    changed = [path for path, content in outputs.items() if write_if_changed(path, content)]
    if args.check:
        # --check tidak menulis; bandingkan dengan isi sekarang.
        changed = []
        for path, content in outputs.items():
            current = path.read_text(encoding="utf-8") if path.exists() else ""
            if current != content:
                changed.append(path)
        if changed:
            say("DRIFT: YAML berbeda dari instance untuk:")
            for path in changed:
                say(f"  - {path.relative_to(iac_dir)}")
            say("Jalankan `python3 tools/export.py` untuk menyelaraskan, "
                "atau `apply.py` bila instance yang harus mengikuti YAML.")
            return 1
        say("OK: YAML identik dengan instance.")
        return 0

    say(f"{len(outputs)} file YAML ditulis ({len(changed)} berubah).")
    for path in sorted(outputs):
        marker = "diperbarui" if path in changed else "tetap"
        say(f"  - {path.relative_to(iac_dir)} ({marker})")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except ResolveError as exc:
        say(f"GAGAL: {exc}")
        sys.exit(2)
