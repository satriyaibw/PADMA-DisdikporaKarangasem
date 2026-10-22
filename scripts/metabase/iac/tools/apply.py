#!/usr/bin/env python3
"""Terapkan spec YAML ke instance Metabase (idempoten).

    python3 tools/apply.py --dry-run     # lihat rencana, tidak menulis
    python3 tools/apply.py               # terapkan
    python3 tools/apply.py --keep-extra  # jangan hapus tab/kartu di luar spec

Urutan kerja:
1. resolusi database & collection (dibuat bila belum ada),
2. 6 kartu bantu dropdown + 46 kartu analitik (create/update by nama),
3. dashboard: 6 tab, 9 parameter, 46 slot grid, mapping parameter,
4. public link (bila `public_link: true` dan belum ada),
5. cetak potongan `.env` untuk aplikasi Laravel.

Spec adalah sumber kebenaran: tab/kartu/parameter yang ada di instance tetapi
tidak ada di spec akan DIHAPUS (kecuali `--keep-extra`). Semua penghapusan
dicetak lebih dulu supaya operator bisa menghentikan proses.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import Any

import desired
import resolve_cards
import spec
from mb_client import MetabaseError, connect, say
from resolver import ResolveError, Resolver
from spec import DashboardSpec, SpecError


class Applier:
    def __init__(self, dashboard: DashboardSpec, resolver: Resolver, write: bool,
                 keep_extra: bool, verbose: bool) -> None:
        self.spec = dashboard
        self.resolver = resolver
        self.mb = resolver.mb
        self.write = write
        self.keep_extra = keep_extra
        self.verbose = verbose
        self.card_ids: dict[str, int] = {}
        self.changes = 0
        self.helper_ids: dict[str, int] = {}
        self.live_dashboard: dict[str, Any] | None = None
        self._collections: dict[str, int | None] = {}

    # -- util --------------------------------------------------------------
    def report(self, message: str) -> None:
        print(message)

    def detail(self, message: str) -> None:
        if self.verbose:
            print(f"      {message}")

    def collection(self, name: str) -> int | None:
        if name in self._collections:
            return self._collections[name]
        try:
            collection_id = self.resolver.collection_id(name, create=False)
        except ResolveError:
            if self.write:
                collection_id = self.resolver.collection_id(name, create=True)
                self.report(f"  + collection dibuat: {name} (id {collection_id})")
                self.changes += 1
            else:
                self.report(f"  ! collection belum ada: {name} (akan dibuat saat apply)")
                collection_id = None
        self._collections[name] = collection_id
        return collection_id

    # -- kartu -------------------------------------------------------------
    def sync_cards(self, known: dict[str, int]) -> None:
        print("\nKartu:")
        created = updated = skipped = 0
        database_id = self.resolver.database_id
        for card in self.spec.cards:
            collection_id = self.collection(card.collection)
            if collection_id is None:
                self.card_ids[card.key] = 0
                continue
            payload = desired.card_payload(card, self.resolver, database_id, collection_id)
            card_id = known.get(card.key)
            if not card_id:
                self.report(f"  + {card.key:9} {card.name} (dibuat)")
                created += 1
                self.changes += 1
                if self.write:
                    created_card = self.mb.post("/api/card", payload)
                    self.card_ids[card.key] = int(created_card["id"])
                continue
            current = self.mb.get(f"/api/card/{card_id}")
            problems = desired.card_problems(current, payload)
            self.card_ids[card.key] = card_id
            if not problems:
                skipped += 1
                self.detail(f"{card.key}: sesuai (id {card_id})")
                continue
            self.report(f"  ~ {card.key:9} {card.name} (id {card_id})")
            for problem in problems:
                self.detail(problem)
            updated += 1
            self.changes += 1
            if self.write:
                self.mb.put(f"/api/card/{card_id}", payload)
        print(f"  {created} dibuat, {updated} diperbarui, {skipped} sudah sesuai")

    # -- dashboard ---------------------------------------------------------
    def plan_tabs(self, current: dict[str, Any] | None) -> tuple[list[dict[str, Any]], dict[str, int]]:
        existing = {tab["name"]: tab for tab in (current or {}).get("tabs") or []}
        payload: list[dict[str, Any]] = []
        identifier: dict[str, int] = {}
        next_temp = -1
        for tab in self.spec.tabs:
            found = existing.pop(tab.name, None)
            tab_id = int(found["id"]) if found else next_temp
            if not found:
                next_temp -= 1
                self.changes += 1
                self.report(f"  + tab dibuat: {tab.name}")
            payload.append({"id": tab_id, "name": tab.name})
            identifier[tab.key] = tab_id
        return payload, identifier

    def plan_dashcards(
        self,
        current: dict[str, Any] | None,
        tab_ids: dict[str, int],
    ) -> list[dict[str, Any]]:
        tab_name_by_id = {tab["id"]: tab["name"] for tab in (current or {}).get("tabs") or []}
        remaining: dict[tuple[str | None, int], dict[str, Any]] = {}
        for dashcard in (current or {}).get("dashcards") or []:
            key = (tab_name_by_id.get(dashcard.get("dashboard_tab_id")), int(dashcard["card_id"]))
            remaining[key] = dashcard

        payload: list[dict[str, Any]] = []
        next_temp = -1
        for tab in self.spec.tabs:
            for card in self.spec.cards_for_tab(tab.key):
                card_id = self.card_ids.get(card.key, 0)
                existing = remaining.pop((tab.name, card_id), None)
                grid = card.layout
                assert grid is not None
                dashcard_id = int(existing["id"]) if existing else next_temp
                if existing is None:
                    next_temp -= 1
                    self.changes += 1
                    self.report(f"  + slot {tab.name}/{card.key} ({grid.w}x{grid.h} @ {grid.row},{grid.col})")
                else:
                    moved = (existing.get("row"), existing.get("col")) != (grid.row, grid.col)
                    resized = (existing.get("size_x"), existing.get("size_y")) != (grid.w, grid.h)
                    if moved or resized:
                        self.changes += 1
                        self.report(f"  ~ slot {tab.name}/{card.key} (posisi/ukuran disesuaikan)")
                payload.append(
                    {
                        "id": dashcard_id,
                        "card_id": card_id,
                        "dashboard_tab_id": tab_ids[tab.key],
                        "row": grid.row,
                        "col": grid.col,
                        "size_x": grid.w,
                        "size_y": grid.h,
                        "series": (existing or {}).get("series") or [],
                        "parameter_mappings": desired.mappings_for(card, card_id),
                    }
                )
        return payload, remaining  # type: ignore[return-value]

    def plan_parameters(self, current: dict[str, Any] | None) -> list[dict[str, Any]]:
        existing = {param["id"]: param for param in (current or {}).get("parameters") or []}
        payload: list[dict[str, Any]] = []
        for param in self.spec.parameters:
            helper_id = self.helper_ids.get(param.from_card or "", 0)
            wanted = desired.parameter_payload(param, helper_id)
            have = existing.pop(param.key, None)
            if have is None:
                self.changes += 1
                self.report(f"  + parameter {param.key} ({param.source})")
            elif desired.parameter_problems(have, wanted):
                self.changes += 1
                self.report(f"  ~ parameter {param.key} ({param.source})")
                for problem in desired.parameter_problems(have, wanted):
                    self.detail(problem)
            payload.append(wanted)
        return payload, existing  # type: ignore[return-value]

    def plan_mappings(self, dashcards: list[dict[str, Any]], current: dict[str, Any] | None) -> None:
        """Laporkan mapping yang berubah, termasuk yang hanya soal urutan."""
        current_by_card: dict[int, list[dict[str, Any]]] = {}
        for dashcard in (current or {}).get("dashcards") or []:
            current_by_card.setdefault(int(dashcard["card_id"]), []).append(dashcard)
        for item in dashcards:
            card_id = item["card_id"]
            desired_mappings = item["parameter_mappings"]
            for dashcard in current_by_card.get(card_id, []):
                problems = desired.mapping_problems(dashcard.get("parameter_mappings") or [], desired_mappings)
                if problems:
                    self.changes += 1
                    self.detail(f"kartu {card_id}: {problems[0]}")

    def sync_dashboard(self) -> None:
        print("\nDashboard:")
        before = self.changes
        current = self.live_dashboard

        tabs_payload, tab_ids = self.plan_tabs(current)
        dashcards_payload, leftover = self.plan_dashcards(current, tab_ids)
        parameters_payload, leftover_parameters = self.plan_parameters(current)

        if leftover and self.keep_extra:
            for (tab_name, card_id), dashcard in leftover.items():
                dashcards_payload.append(
                    {
                        "id": int(dashcard["id"]),
                        "card_id": card_id,
                        "dashboard_tab_id": dashcard.get("dashboard_tab_id"),
                        "row": dashcard["row"],
                        "col": dashcard["col"],
                        "size_x": dashcard["size_x"],
                        "size_y": dashcard["size_y"],
                        "series": dashcard.get("series") or [],
                        "parameter_mappings": dashcard.get("parameter_mappings") or [],
                    }
                )
        elif leftover:
            for (tab_name, card_id), dashcard in sorted(
                leftover.items(), key=lambda item: str(item[0])
            ):
                self.report(
                    f"  - DIHAPUS slot {tab_name or '?'}/kartu {card_id} "
                    "(tidak ada di spec; pakai --keep-extra untuk mempertahankan)"
                )
                self.changes += 1

        if leftover_parameters and not self.keep_extra:
            for key in sorted(leftover_parameters):
                self.report(f"  - DIHAPUS parameter {key} (tidak ada di spec)")
                self.changes += 1

        if current is None:
            if not self.write:
                print(
                    f"  ! dashboard '{self.spec.name}' belum ada "
                    f"({len(tabs_payload)} tab, {len(dashcards_payload)} slot, "
                    f"{len(parameters_payload)} parameter akan dibuat)"
                )
                return
            created = self.mb.post(
                "/api/dashboard", {"name": self.spec.name, "description": self.spec.description}
            )
            current = self.resolver.dashboard(int(created["id"]))
            self.report(f"  + dashboard dibuat: id {current['id']}")
            self.changes += 1

        meta_changes = []
        if current.get("name") != self.spec.name:
            meta_changes.append("name")
        if (current.get("description") or "") != (self.spec.description or ""):
            meta_changes.append("description")
        if current.get("width") != self.spec.width:
            meta_changes.append("width")
        if bool(current.get("auto_apply_filters", True)) != self.spec.auto_apply_filters:
            meta_changes.append("auto_apply_filters")
        if meta_changes:
            self.changes += 1
            self.report(f"  ~ metadata dashboard: {', '.join(meta_changes)}")

        body = {
            "name": self.spec.name,
            "description": self.spec.description or None,
            "width": self.spec.width,
            "auto_apply_filters": self.spec.auto_apply_filters,
            "parameters": parameters_payload,
            "tabs": tabs_payload,
            "dashcards": dashcards_payload,
        }
        self.plan_mappings(dashcards_payload, current)

        if self.changes == before and current is not None:
            print("  sudah sesuai (tidak ada perubahan tab, slot, parameter, atau mapping)")
            self.dashboard_id = int(current["id"])
            self.public_uuid = current.get("public_uuid")
            return

        if self.write:
            updated = self.mb.put(f"/api/dashboard/{current['id']}", body)
            current = updated or self.resolver.dashboard(int(current["id"]))
            print(
                f"  PUT /api/dashboard/{current['id']}: "
                f"{len(tabs_payload)} tab, {len(dashcards_payload)} slot, "
                f"{len(parameters_payload)} parameter"
            )
        else:
            print(
                f"  akan PUT /api/dashboard/{current['id']} "
                f"({len(tabs_payload)} tab, {len(dashcards_payload)} slot, "
                f"{len(parameters_payload)} parameter)"
            )

        self.dashboard_id = int(current["id"])
        self.public_uuid = current.get("public_uuid")

    # -- public link -------------------------------------------------------
    def sync_public_link(self) -> None:
        if not self.spec.public_link:
            return
        if self.public_uuid:
            return
        if not self.write:
            print("\nPublic link: akan dibuat (POST /api/dashboard/:id/public_link)")
            return
        print("\nPublic link:")
        try:
            result = self.mb.post(f"/api/dashboard/{self.dashboard_id}/public_link")
            self.public_uuid = (result or {}).get("uuid")
            self.report(f"  + public link dibuat: {self.public_uuid}")
        except MetabaseError as exc:
            self.report(
                f"  ! gagal membuat public link ({exc.status}). "
                "Pastikan setting 'enable-public-sharing' aktif (Admin › Settings › Public sharing)."
            )

    # -- ringkasan ---------------------------------------------------------
    def print_env(self) -> None:
        if not getattr(self, "public_uuid", None):
            return
        print("\nTempelkan ke dashboard-karangasem/.env (atau secret manager):")
        print(f"  METABASE_PUBLIC_DASHBOARD_ID={self.dashboard_id}")
        print(f"  METABASE_PUBLIC_DASHBOARD_UUID={self.public_uuid}")
        print("\nLalu di aplikasi: vendor/bin/sail artisan config:clear")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dry-run", action="store_true", help="tampilkan rencana tanpa menulis")
    parser.add_argument("--keep-extra", action="store_true",
                        help="jangan hapus tab/kartu/parameter yang tidak ada di spec")
    parser.add_argument("--verbose", action="store_true", help="tampilkan detail tiap perbedaan")
    parser.add_argument("--force", action="store_true",
                        help="tetap terapkan walau tata letak grid bermasalah (tidak disarankan)")
    parser.add_argument("--url", help="URL Metabase (default: deteksi otomatis)")
    parser.add_argument("--env-file", help="file .env berisi kredensial Metabase")
    parser.add_argument("--iac-dir", help="direktori spec (default: induk tools/)")
    args = parser.parse_args()

    iac_dir = Path(args.iac_dir).resolve() if args.iac_dir else Path(__file__).resolve().parents[1]
    try:
        dashboard = spec.load(iac_dir)
    except SpecError as exc:
        say(f"GAGAL: {exc}")
        return 2

    # Preflight: tumpang tindih sel membuat footer dashboard tertimpa kartu
    # terakhir (lihat AGENTS.md). Jangan pernah dikirim ke instance.
    grid_problems = spec.layout_problems(dashboard.cards)
    if grid_problems and not args.force:
        say("GAGAL: tata letak grid bermasalah, apply dibatalkan:")
        for problem in grid_problems[:20]:
            say(f"  - {problem}")
        say("Perbaiki layout di YAML (atau jalankan --force bila benar-benar yakin).")
        return 2

    mb, base, env_path = connect(args.url, args.env_file)
    say(f"Metabase: {base}" + (f" (kredensial: {env_path})" if env_path else ""))
    say(f"Mode: {'DRY-RUN (tidak menulis)' if args.dry_run else 'APPLY'}")

    resolver = Resolver(mb, dashboard.database)
    applier = Applier(dashboard, resolver, write=not args.dry_run,
                      keep_extra=args.keep_extra, verbose=args.verbose)
    try:
        existing = resolver.find_dashboard(dashboard.name)
        applier.live_dashboard = resolver.dashboard(int(existing["id"])) if existing else None
        known, notes = resolve_cards.resolve_card_ids(dashboard, resolver, applier.live_dashboard)
        for note in notes:
            print(f"  catatan: {note}")
        applier.sync_cards(known)
        applier.helper_ids = {card.key: applier.card_ids[card.key] for card in dashboard.helper_cards}
        applier.sync_dashboard()
        applier.sync_public_link()
    except ResolveError as exc:
        say(f"GAGAL: {exc}")
        return 2
    except MetabaseError as exc:
        say(f"GAGAL memanggil Metabase: {exc}")
        return 2

    print(f"\nTotal perubahan: {applier.changes}")
    applier.print_env()
    if args.dry_run:
        print("\nDry-run selesai. Jalankan tanpa --dry-run untuk menerapkan.")
    else:
        print("\nSelesai. Verifikasi dengan: python3 tools/verify.py")
    return 0


if __name__ == "__main__":
    sys.exit(main())
