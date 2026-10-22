#!/usr/bin/env python3
"""Resolusi nama -> id Metabase pada instance target.

Spec tidak menyimpan id numerik (field 1790, collection 5, card 51, ...) karena
id tersebut berbeda di setiap instance. Modul ini menerjemahkan rujukan
deklaratif menjadi id nyata:

    database   "backbone_client"                      -> GET /api/database
    collection "Dashboard Publik — Kartu"             -> GET/POST /api/collection
    field      (schema, table, column)                -> GET /api/database/:id/metadata
    card       (nama, collection)                     -> GET /api/collection/:id/items

Semua hasil di-cache selama satu proses supaya apply/verify tidak memanggil
metadata berulang kali (metadata `backbone_client` ±154 tabel).
"""
from __future__ import annotations

from typing import Any

from mb_client import Metabase, MetabaseError
from spec import FieldRef


class ResolveError(RuntimeError):
    """Nama di spec tidak ada di instance target."""


class Resolver:
    def __init__(self, mb: Metabase, database_name: str) -> None:
        self.mb = mb
        self.database_name = database_name
        self._database_id: int | None = None
        self._fields: dict[tuple[str, str, str], dict[str, Any]] | None = None
        self._collections: dict[str, int] | None = None

    # -- database ----------------------------------------------------------
    @property
    def database_id(self) -> int:
        if self._database_id is None:
            payload = self.mb.get("/api/database")
            databases = payload.get("data", payload) if isinstance(payload, dict) else payload
            for database in databases or []:
                if database.get("name") == self.database_name:
                    self._database_id = int(database["id"])
                    break
            else:
                names = ", ".join(d.get("name", "?") for d in databases or [])
                raise ResolveError(
                    f"Database '{self.database_name}' belum terdaftar di Metabase ini.\n"
                    f"Database yang ada: {names}\n"
                    "Tambahkan koneksi dulu (Admin › Databases), lalu ulangi."
                )
        return self._database_id

    # -- field -------------------------------------------------------------
    def _field_index(self) -> dict[tuple[str, str, str], dict[str, Any]]:
        if self._fields is None:
            metadata = self.mb.get(f"/api/database/{self.database_id}/metadata")
            index: dict[tuple[str, str, str], dict[str, Any]] = {}
            for table in metadata.get("tables") or []:
                schema = table.get("schema") or ""
                table_name = table.get("name") or ""
                for column in table.get("fields") or []:
                    key = (schema, table_name, column.get("name") or "")
                    index[key] = {
                        "id": int(column["id"]),
                        "base_type": column.get("base_type"),
                        "effective_type": column.get("effective_type"),
                        "table_schema": schema,
                        "table_name": table_name,
                        "name": column.get("name"),
                    }
            self._fields = index
        return self._fields

    def field_by_id(self, field_id: int) -> dict[str, Any]:
        """Kebalikan `field()`: dipakai export untuk menulis rujukan deklaratif."""
        index = self._field_index()
        for meta in index.values():
            if meta["id"] == field_id:
                return meta
        raise ResolveError(
            f"Field id {field_id} tidak ada di metadata database '{self.database_name}'. "
            "Jalankan sync metadata Metabase lalu ulangi export."
        )

    def field(self, ref: FieldRef) -> dict[str, Any]:
        index = self._field_index()
        key = (ref.schema, ref.table, ref.column)
        if key in index:
            return index[key]
        # Toleransi salah kapital (PostgreSQL di dump ini memakai nama huruf kecil).
        lowered = (ref.schema.lower(), ref.table.lower(), ref.column.lower())
        for candidate, meta in index.items():
            if (candidate[0].lower(), candidate[1].lower(), candidate[2].lower()) == lowered:
                return meta
        near = sorted({f"{s}.{t}.{c}" for s, t, c in index if t.lower() == ref.table.lower()})[:8]
        raise ResolveError(
            f"Kolom {ref.label()} tidak ditemukan di database '{self.database_name}'.\n"
            + (f"Kolom yang ada di tabel itu: {', '.join(near)}\n" if near else "")
            + "Jalankan sync metadata di Metabase, atau perbaiki rujukan field di YAML."
        )

    # -- collection --------------------------------------------------------
    def collections(self) -> dict[str, int]:
        if self._collections is None:
            listing = self.mb.get("/api/collection")
            # Collection "root" memakai id string ("root"), bukan angka.
            self._collections = {
                item.get("name"): int(item["id"])
                for item in listing or []
                if item.get("name") and str(item.get("id", "")).isdigit()
            }
        return self._collections

    def collection_id(self, name: str, create: bool = False) -> int:
        collections = self.collections()
        if name in collections:
            return collections[name]
        if not create:
            raise ResolveError(f"Collection '{name}' tidak ada di Metabase ini.")
        created = self.mb.post("/api/collection", {"name": name, "parent_id": None})
        collections[name] = int(created["id"])
        return collections[name]

    # -- card --------------------------------------------------------------
    def cards_in(self, collection_id: int) -> list[dict[str, Any]]:
        items: list[dict[str, Any]] = []
        offset = 0
        while True:
            payload = self.mb.get(
                f"/api/collection/{collection_id}/items",
                query={"models": "card", "limit": "200", "offset": str(offset)},
            )
            batch = (payload or {}).get("data") or []
            items.extend(batch)
            offset += len(batch)
            total = (payload or {}).get("total")
            if not batch or (total is not None and offset >= int(total)):
                break
        return items

    def find_card(self, name: str, collection_id: int) -> dict[str, Any] | None:
        matches = [card for card in self.cards_in(collection_id) if card.get("name") == name]
        if len(matches) > 1:
            raise ResolveError(
                f"Nama kartu '{name}' duplikat di collection {collection_id} "
                f"(id: {[m['id'] for m in matches]}). Nama kartu harus unik."
            )
        return matches[0] if matches else None

    # -- dashboard ---------------------------------------------------------
    def dashboards(self) -> list[dict[str, Any]]:
        return self.mb.get("/api/dashboard") or []

    def find_dashboard(self, name: str) -> dict[str, Any] | None:
        matches = [
            item
            for item in self.dashboards()
            if item.get("name") == name and not item.get("archived")
        ]
        if len(matches) > 1:
            raise ResolveError(
                f"Nama dashboard '{name}' duplikat (id: {[m['id'] for m in matches]}). "
                "Arsipkan salah satu dulu."
            )
        return matches[0] if matches else None

    def dashboard(self, dashboard_id: int) -> dict[str, Any]:
        try:
            return self.mb.get(f"/api/dashboard/{dashboard_id}")
        except MetabaseError as exc:
            if exc.status == 404:
                raise ResolveError(f"Dashboard id {dashboard_id} tidak ditemukan.") from None
            raise
