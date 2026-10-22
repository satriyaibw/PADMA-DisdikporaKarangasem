#!/usr/bin/env python3
"""Bentuk payload Metabase yang "diinginkan" oleh spec + pembandingnya.

Dipakai bersama oleh `apply.py` (menulis) dan `verify.py` (memeriksa), supaya
aturan konversi YAML -> API hanya ditulis sekali:

* `filters[]`  -> `dataset_query.native.template-tags` (field filter dimension)
* `filters[]`  -> `dashcard.parameter_mappings` (target `["dimension", ["template-tag", tag]]`)
* parameter    -> objek parameter dashboard (`values_source_type` card/static-list)

Semua perbandingan bersifat *semantic* (SQL dinormalkan, mapping diurutkan),
bukan perbandingan JSON mentah, agar apply kedua tidak menulis apa pun.
"""
from __future__ import annotations

import json
from typing import Any

from resolver import Resolver
from spec import CardSpec, ParamSpec, is_numeric, normalise_sql


# ---------------------------------------------------------------------------
# Kartu
# ---------------------------------------------------------------------------
def build_tags(card: CardSpec, resolver: Resolver) -> dict[str, dict[str, Any]]:
    """Bangun `template-tags` dari daftar filter deklaratif.

    Field filter harus menunjuk kolom yang ada di FROM kartu, jadi tiap filter
    menyebut field-nya sendiri. Id field diresolusi dari metadata instance
    target, bukan ditulis di YAML.
    """
    tags: dict[str, dict[str, Any]] = {}
    for item in card.filters:
        meta = resolver.field(item.field)
        widget = item.widget or ("number/=" if is_numeric(meta.get("base_type")) else "string/=")
        tags[item.tag] = {
            "type": "dimension",
            "name": item.tag,
            "display-name": item.label,
            "widget-type": widget,
            "dimension": ["field", meta["id"], None],
        }
    return tags


def card_payload(card: CardSpec, resolver: Resolver, database_id: int, collection_id: int) -> dict[str, Any]:
    return {
        "name": card.name,
        "description": card.description or None,
        "display": card.display,
        "collection_id": collection_id,
        "visualization_settings": card.visualization_settings or {},
        "dataset_query": {
            "database": database_id,
            "type": "native",
            "native": {"query": card.sql, "template-tags": build_tags(card, resolver)},
        },
    }


def card_problems(current: dict[str, Any], payload: dict[str, Any]) -> list[str]:
    """Daftar perbedaan kartu di instance vs payload yang diinginkan."""
    problems: list[str] = []
    for key in ("name", "display", "collection_id"):
        if current.get(key) != payload.get(key):
            problems.append(f"{key}: instance={current.get(key)!r} spec={payload.get(key)!r}")
    if (current.get("description") or None) != (payload.get("description") or None):
        problems.append("description berbeda")

    current_query = current.get("dataset_query") or {}
    desired_query = payload["dataset_query"]
    if current_query.get("database") != desired_query["database"]:
        problems.append(
            f"database: instance={current_query.get('database')} spec={desired_query['database']}"
        )
    current_native = current_query.get("native") or {}
    desired_native = desired_query["native"]
    if normalise_sql(current_native.get("query") or "") != normalise_sql(desired_native["query"]):
        problems.append("SQL berbeda")

    current_tags = current_native.get("template-tags") or {}
    desired_tags = desired_native.get("template-tags") or {}
    if set(current_tags) != set(desired_tags):
        problems.append(
            f"tag: instance={sorted(current_tags)} spec={sorted(desired_tags)}"
        )
    for name, desired_tag in desired_tags.items():
        current_tag = current_tags.get(name)
        if not current_tag:
            continue
        if current_tag.get("widget-type") != desired_tag.get("widget-type"):
            problems.append(
                f"tag {name}: widget-type instance={current_tag.get('widget-type')!r} "
                f"spec={desired_tag.get('widget-type')!r}"
            )
        if current_tag.get("dimension") != desired_tag.get("dimension"):
            problems.append(
                f"tag {name}: dimension instance={current_tag.get('dimension')!r} "
                f"spec={desired_tag.get('dimension')!r}"
            )
        if (current_tag.get("display-name") or "") != (desired_tag.get("display-name") or ""):
            problems.append(f"tag {name}: display-name berbeda")

    if json.dumps(current.get("visualization_settings") or {}, sort_keys=True) != json.dumps(
        payload.get("visualization_settings") or {}, sort_keys=True
    ):
        problems.append("visualization_settings berbeda")
    return problems


# ---------------------------------------------------------------------------
# Parameter dashboard
# ---------------------------------------------------------------------------
def parameter_payload(param: ParamSpec, helper_card_id: int | None = None) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "id": param.key,
        "name": param.name,
        "slug": param.slug,
        "type": param.type,
        "sectionId": param.section,
        "isMultiSelect": param.multi,
    }
    if param.from_card:
        if helper_card_id is None:
            raise ValueError(f"Parameter {param.key} butuh kartu bantu {param.from_card}")
        payload["values_source_type"] = "card"
        payload["values_source_config"] = {
            "card_id": helper_card_id,
            "value_field": ["field", param.value_field, {"base-type": "type/Text"}],
        }
    else:
        payload["values_source_type"] = "static-list"
        payload["values_source_config"] = {
            "values": [list(item) for item in param.static_list]
        }
    return payload


def parameter_problems(current: dict[str, Any], desired: dict[str, Any]) -> list[str]:
    problems: list[str] = []
    for key, value in desired.items():
        if key == "values_source_config":
            current_config = current.get(key) or {}
            if "values" in value:
                if [list(item) for item in current_config.get("values") or []] != value["values"]:
                    problems.append("urutan/isi static list berbeda")
            else:
                if current_config.get("card_id") != value.get("card_id"):
                    problems.append(
                        f"values_source_config.card_id instance={current_config.get('card_id')} "
                        f"spec={value.get('card_id')}"
                    )
                if current_config.get("value_field") != value.get("value_field"):
                    problems.append("values_source_config.value_field berbeda")
            continue
        if current.get(key) != value:
            problems.append(f"{key}: instance={current.get(key)!r} spec={value!r}")
    return problems


# ---------------------------------------------------------------------------
# Dashcard
# ---------------------------------------------------------------------------
def mappings_for(card: CardSpec, card_id: int) -> list[dict[str, Any]]:
    return [
        {
            "parameter_id": item.parameter,
            "card_id": card_id,
            "target": ["dimension", ["template-tag", item.tag]],
        }
        for item in card.filters
    ]


def mapping_key(mapping: dict[str, Any]) -> tuple[str, str]:
    return mapping.get("parameter_id") or "", json.dumps(mapping.get("target"), sort_keys=True)


def mapping_problems(current: list[dict[str, Any]], desired: list[dict[str, Any]]) -> list[str]:
    have = sorted(mapping_key(item) for item in current or [])
    want = sorted(mapping_key(item) for item in desired)
    if have == want:
        return []
    missing = [key for key in want if key not in have]
    extra = [key for key in have if key not in want]
    detail = []
    if missing:
        detail.append(f"kurang {[key[0] for key in missing]}")
    if extra:
        detail.append(f"berlebih {[key[0] for key in extra]}")
    return ["mapping parameter: " + ", ".join(detail)]
