#!/usr/bin/env python3
"""Tentukan id kartu di instance target untuk tiap `key` di spec.

Nama kartu **tidak selalu unik**: dashboard publik punya dua kartu bernama
"Total Rombel Aktif (Kelas)" (Q-EXE-02 dan Q-ROM-01) dan dua "Total Peserta
Didik Aktif" (Q-EXE-03 dan Q-PD-01), karena KPI yang sama muncul di dua tab.

Karena itu identitas kartu ditentukan berlapis:

1. **slot dashboard** `(nama tab, nama kartu)` -- sumber paling kuat, karena
   posisi kartu di tab tertentu sudah membedakan duplikat;
2. **nama unik di collection** -- dipakai untuk kartu bantu dropdown;
3. **SQL identik di antara nama yang sama** -- cadangan bila dashboard belum
   memuat kartu tersebut tetapi kartunya sudah ada di collection.

Bila tidak ada lapisan yang cocok, kartu dianggap baru (akan dibuat).
"""
from __future__ import annotations

from typing import Any

from resolver import ResolveError, Resolver
from spec import DashboardSpec, normalise_sql


def _slot_index(live: dict[str, Any] | None) -> dict[tuple[str, str], int]:
    tab_name = {tab["id"]: tab["name"] for tab in (live or {}).get("tabs") or []}
    index: dict[tuple[str, str], int] = {}
    for dashcard in (live or {}).get("dashcards") or []:
        card = dashcard.get("card") or {}
        name = card.get("name")
        tab = tab_name.get(dashcard.get("dashboard_tab_id"))
        if name and tab:
            index.setdefault((tab, name), int(dashcard["card_id"]))
    return index


def resolve_card_ids(
    dashboard: DashboardSpec,
    resolver: Resolver,
    live: dict[str, Any] | None,
) -> tuple[dict[str, int], list[str]]:
    """Kembalikan ({key: card_id}, catatan) untuk kartu yang sudah ada."""
    slots = _slot_index(live)
    tab_names = {tab.key: tab.name for tab in dashboard.tabs}
    by_collection: dict[str, list[dict[str, Any]]] = {}
    notes: list[str] = []
    ids: dict[str, int] = {}

    for card in dashboard.cards:
        if card.tab:
            card_id = slots.get((tab_names[card.tab], card.name))
            if card_id:
                ids[card.key] = card_id
                continue

        try:
            collection_id = resolver.collection_id(card.collection, create=False)
        except ResolveError:
            continue  # instance baru: collection belum ada, semua kartu akan dibuat
        if collection_id not in by_collection:
            by_collection[collection_id] = resolver.cards_in(collection_id)
        candidates = [item for item in by_collection[collection_id] if item.get("name") == card.name]

        if len(candidates) == 1:
            ids[card.key] = int(candidates[0]["id"])
            if card.tab:
                notes.append(
                    f"{card.key}: ditemukan lewat nama di collection, bukan slot dashboard"
                )
            continue
        if len(candidates) > 1:
            # Nama duplikat: pilih yang SQL-nya identik dengan spec.
            same_sql = []
            for candidate in candidates:
                detail = resolver.mb.get(f"/api/card/{candidate['id']}")
                sql = ((detail.get("dataset_query") or {}).get("native") or {}).get("query") or ""
                if normalise_sql(sql) == card.sql:
                    same_sql.append(int(candidate["id"]))
            if len(same_sql) == 1:
                ids[card.key] = same_sql[0]
                notes.append(f"{card.key}: nama duplikat, dicocokkan lewat SQL identik")
            else:
                notes.append(
                    f"{card.key}: {len(candidates)} kartu bernama '{card.name}' dan tidak ada "
                    "yang cocok lewat slot/SQL; akan dibuat sebagai kartu baru"
                )
    return ids, notes
