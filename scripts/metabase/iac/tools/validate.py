#!/usr/bin/env python3
"""Lint offline spec "card as code" (tanpa koneksi Metabase).

    python3 tools/validate.py

Exit 0 = spec konsisten. Exit 1 = ada masalah yang harus diperbaiki.
Semua aturan di bawah menangkap kesalahan yang pernah terjadi di dashboard ini,
jadi pesannya menyebut akibatnya, bukan hanya "invalid".
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

import spec
from spec import CardSpec, DashboardSpec, SpecError

SCALAR_DISPLAYS = {"scalar", "smartscalar"}
FLOW_DISPLAYS = {"table", "bar", "row", "pie", "line", "area", "combo"}


def check_identity(dashboard: DashboardSpec) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    keys = [card.key for card in dashboard.cards]
    duplicates = {key for key in keys if keys.count(key) > 1}
    if duplicates:
        errors.append(f"kunci kartu duplikat: {sorted(duplicates)}")

    # Nama kartu boleh sama antar tab (KPI yang muncul di dua tab), tetapi tidak
    # boleh sama di tab yang sama: identitas kartu di-resolve lewat slot
    # (tab, nama), jadi duplikat dalam satu tab tidak bisa dibedakan.
    per_tab: dict[tuple[str | None, str], int] = {}
    for card in dashboard.cards:
        key = (card.tab, card.name)
        per_tab[key] = per_tab.get(key, 0) + 1
    for (tab, name), count in per_tab.items():
        if count > 1:
            errors.append(
                f"nama kartu '{name}' muncul {count}x di tab '{tab or 'bantu'}'; "
                "identitas kartu memakai slot (tab, nama) sehingga harus unik per tab"
            )

    tab_keys = [tab.key for tab in dashboard.tabs]
    if not tab_keys:
        errors.append("dashboard tidak punya tab")
    if len(set(tab_keys)) != len(tab_keys):
        errors.append(f"kunci tab duplikat: {tab_keys}")
    param_keys = [param.key for param in dashboard.parameters]
    if len(set(param_keys)) != len(param_keys):
        errors.append(f"kunci parameter duplikat: {param_keys}")
    return errors, warnings


def check_cards(dashboard: DashboardSpec) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    tab_keys = {tab.key for tab in dashboard.tabs}
    param_keys = {param.key for param in dashboard.parameters}
    helper_keys = {card.key for card in dashboard.helper_cards}

    for card in dashboard.cards:
        where = f"{card.key} ({card.name})"
        if card.tab and card.tab not in tab_keys:
            errors.append(f"{where}: tab '{card.tab}' tidak ada di dashboard.public.yaml")
        if not card.tab and card.key not in helper_keys:
            errors.append(f"{where}: kartu bantu harus terdaftar lewat parameter values.from_card")
        if not card.description:
            warnings.append(f"{where}: description kosong (tampil sebagai tooltip kosong)")

        tags_in_sql = spec.template_tags(card.sql)
        declared = {item.tag for item in card.filters}
        undeclared = tags_in_sql - declared
        unused = declared - tags_in_sql
        if undeclared:
            errors.append(
                f"{where}: tag {sorted(undeclared)} dipakai di SQL tetapi tidak dideklarasikan "
                "di filters[] (field filter tidak akan terpetakan ke dashboard)"
            )
        if unused:
            errors.append(
                f"{where}: filter {sorted(unused)} dideklarasikan tetapi tidak ada di SQL"
            )
        seen: set[str] = set()
        for item in card.filters:
            if item.tag in seen:
                errors.append(f"{where}: tag '{item.tag}' dideklarasikan dua kali")
            seen.add(item.tag)
            if item.parameter not in param_keys:
                errors.append(
                    f"{where}: filter '{item.tag}' menunjuk parameter '{item.parameter}' "
                    "yang tidak ada di dashboard.public.yaml"
                )

        if card.display in SCALAR_DISPLAYS:
            if "value" not in card.expect:
                warnings.append(f"{where}: display scalar sebaiknya punya expect.value")
        elif card.display in FLOW_DISPLAYS and "rows" not in card.expect:
            warnings.append(f"{where}: display {card.display} sebaiknya punya expect.rows")
    return errors, warnings


def check_parameters(dashboard: DashboardSpec) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    used = {item.parameter for card in dashboard.cards for item in card.filters}
    helper_keys = {card.key for card in dashboard.helper_cards}

    for param in dashboard.parameters:
        where = f"parameter {param.key}"
        if not re.fullmatch(r"[a-z0-9-]+", param.key):
            errors.append(f"{where}: kunci harus huruf kecil/angka/strip (dipakai sebagai id parameter)")
        if param.from_card:
            if param.from_card not in helper_keys:
                errors.append(f"{where}: kartu bantu '{param.from_card}' tidak ada di cards/filters.yaml")
            if not param.value_field:
                errors.append(f"{where}: values.value_field wajib untuk sumber kartu")
        else:
            if not param.static_list:
                errors.append(f"{where}: static_list kosong")
            for value, label in param.static_list:
                # Metabase mengirim elemen pertama ke query dan menampilkan elemen kedua.
                # Urutan terbalik membuat dropdown menampilkan kode mentah DAN filter gagal.
                if isinstance(value, str) and re.fullmatch(r"\d{4,5}", value.strip()) is None:
                    if re.search(r"\d", label) and re.fullmatch(r"\d{4,5}", label.strip()):
                        errors.append(
                            f"{where}: static list '{value}' / '{label}' tampaknya terbalik; "
                            "formatnya [nilai, label]"
                        )
        if param.key not in used:
            warnings.append(f"{where}: tidak dipetakan ke kartu mana pun (dropdown tidak akan muncul)")
    return errors, warnings


def check_layout(dashboard: DashboardSpec) -> tuple[list[str], list[str]]:
    errors = spec.layout_problems(dashboard.cards)
    warnings: list[str] = []
    for tab in dashboard.tabs:
        cards = dashboard.cards_for_tab(tab.key)
        if not cards:
            errors.append(f"tab '{tab.key}': tidak ada kartu")
            continue
        # Tinggi deklaratif dipakai Metabase untuk memposisikan footer; celah baris
        # tidak berbahaya, tetapi menandakan layout yang belum dirapikan.
        rows: dict[int, list[CardSpec]] = {}
        for card in cards:
            assert card.layout
            rows.setdefault(card.layout.row, []).append(card)
        width = {row: sum(card.layout.w for card in row_cards) for row, row_cards in rows.items()}
        for row, total in sorted(width.items()):
            if total != spec.GRID_COLUMNS:
                warnings.append(
                    f"tab '{tab.key}' row {row}: total lebar {total} (bukan {spec.GRID_COLUMNS})"
                )
    return errors, warnings


def check_expectations(dashboard: DashboardSpec) -> tuple[list[str], list[str]]:
    errors: list[str] = []
    warnings: list[str] = []
    for card in dashboard.cards:
        if not card.expect:
            warnings.append(f"{card.key}: tidak ada expect, verify hanya memeriksa status kueri")
    return errors, warnings


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--iac-dir", help="direktori spec (default: induk tools/)")
    parser.add_argument("--quiet", action="store_true", help="hanya tampilkan masalah")
    args = parser.parse_args()

    iac_dir = Path(args.iac_dir).resolve() if args.iac_dir else Path(__file__).resolve().parents[1]
    try:
        dashboard = spec.load(iac_dir)
    except SpecError as exc:
        print(f"GAGAL: {exc}")
        return 1
    except Exception as exc:  # YAML rusak / tipe aneh
        print(f"GAGAL membaca YAML: {exc}")
        return 1

    errors: list[str] = []
    warnings: list[str] = []
    for check in (check_identity, check_cards, check_parameters, check_layout, check_expectations):
        found_errors, found_warnings = check(dashboard)
        errors.extend(found_errors)
        warnings.extend(found_warnings)

    if not args.quiet:
        analytic = dashboard.analytic_cards
        print(f"Spec: {iac_dir}")
        print(f"  database   : {dashboard.database}")
        print(f"  dashboard  : {dashboard.name}")
        print(f"  tab        : {len(dashboard.tabs)}")
        print(f"  parameter  : {len(dashboard.parameters)}")
        print(f"  kartu      : {len(analytic)} analitik + {len(dashboard.helper_cards)} bantu")
        for tab in dashboard.tabs:
            print(f"    - {tab.name}: {len(dashboard.cards_for_tab(tab.key))} kartu")

    for warning in warnings:
        print(f"PERINGATAN: {warning}")
    for error in errors:
        print(f"ERROR: {error}")

    if errors:
        print(f"\nGAGAL: {len(errors)} error, {len(warnings)} peringatan.")
        return 1
    print(f"\nOK: spec konsisten ({len(warnings)} peringatan).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
