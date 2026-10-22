#!/usr/bin/env python3
"""Model spec "card as code" + loader/validator YAML.

Sumber kebenaran dashboard publik ada di YAML deklaratif di direktori induk:

    dashboard.public.yaml   -> metadata dashboard, 6 tab, 9 parameter
    cards/*.yaml            -> kartu analitik per tab + kartu bantu dropdown

Modul ini sengaja tidak menyentuh jaringan. Semua resolusi nama -> id Metabase
ada di `resolver.py`, sehingga spec bisa di-lint (`validate.py`) tanpa instance.

Aturan penting yang ditegakkan di sini:

* tidak ada id numerik Metabase di YAML -- field dirujuk sebagai
  `{schema, table, column}`, database/collection/kartu dirujuk dengan nama;
* satu tag template = satu filter = satu parameter dashboard (eksplisit),
  karena nama tag tidak selalu sama dengan nama parameter
  (`semester_ruang`/`semester_sanitasi` -> `p-semester`);
* tata letak grid memakai `row` kumulatif dan tidak boleh bertumpuk
  (lihat `layout_problems`).
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Iterable

import yaml

SPEC_ERROR_HINT = "Perbaiki YAML lalu jalankan ulang `python3 tools/validate.py`."

# Tipe dasar Metabase yang butuh widget filter numerik.
NUMERIC_BASE_TYPES = {
    "type/Integer",
    "type/BigInteger",
    "type/SmallInteger",
    "type/Decimal",
    "type/Float",
    "type/Number",
}

GRID_COLUMNS = 24


class SpecError(RuntimeError):
    """YAML tidak sesuai skema. Pesannya menyebut file + field agar mudah dibetulkan."""


# ---------------------------------------------------------------------------
# Utilitas teks
# ---------------------------------------------------------------------------
def slug(text: str) -> str:
    """`Peserta Didik` -> `peserta-didik`."""
    lowered = text.strip().lower()
    lowered = re.sub(r"[^a-z0-9]+", "-", lowered)
    return lowered.strip("-")


def normalise_sql(text: str) -> str:
    """Samakan bentuk SQL untuk perbandingan (abaikan spasi ekor & baris kosong)."""
    return "\n".join(line.rstrip() for line in text.replace("\r\n", "\n").splitlines()).strip()


def is_numeric(base_type: str | None) -> bool:
    return (base_type or "") in NUMERIC_BASE_TYPES


def template_tags(sql: str) -> set[str]:
    """Nama tag `{{...}}` yang benar-benar dipakai di SQL."""
    return set(re.findall(r"\{\{\s*([A-Za-z0-9_]+)\s*\}\}", sql))


# ---------------------------------------------------------------------------
# Dataclass spec
# ---------------------------------------------------------------------------
@dataclass(frozen=True)
class FieldRef:
    schema: str
    table: str
    column: str

    def label(self) -> str:
        return f"{self.schema}.{self.table}.{self.column}"


@dataclass(frozen=True)
class FilterSpec:
    tag: str
    parameter: str
    label: str
    field: FieldRef
    widget: str | None = None


@dataclass(frozen=True)
class Layout:
    row: int
    col: int
    w: int
    h: int


@dataclass(frozen=True)
class CardSpec:
    key: str
    name: str
    collection: str
    display: str
    description: str
    sql: str
    filters: tuple[FilterSpec, ...] = ()
    layout: Layout | None = None
    tab: str | None = None
    expect: dict[str, Any] = field(default_factory=dict)
    visualization_settings: dict[str, Any] = field(default_factory=dict)

    @property
    def is_helper(self) -> bool:
        """Kartu bantu pemasok opsi dropdown (tidak tampil di dashboard)."""
        return self.tab is None

    def expect_text(self) -> str:
        if "value" in self.expect:
            return f"nilai {self.expect['value']}"
        if "rows" in self.expect:
            return f"{self.expect['rows']} baris"
        return "tanpa ekspektasi"


@dataclass(frozen=True)
class TabSpec:
    key: str
    name: str


@dataclass(frozen=True)
class ParamSpec:
    key: str
    name: str
    slug: str
    type: str
    multi: bool = True
    from_card: str | None = None
    value_field: str | None = None
    static_list: tuple[tuple[Any, str], ...] = ()

    @property
    def section(self) -> str:
        return self.type.split("/", 1)[0]

    @property
    def source(self) -> str:
        if self.from_card:
            return f"kartu {self.from_card}"
        return f"static list ({len(self.static_list)} nilai)"


@dataclass(frozen=True)
class DashboardSpec:
    database: str
    name: str
    description: str
    width: str
    auto_apply_filters: bool
    public_link: bool
    tabs: tuple[TabSpec, ...]
    parameters: tuple[ParamSpec, ...]
    cards: tuple[CardSpec, ...]

    @property
    def analytic_cards(self) -> tuple[CardSpec, ...]:
        return tuple(card for card in self.cards if not card.is_helper)

    @property
    def helper_cards(self) -> tuple[CardSpec, ...]:
        return tuple(card for card in self.cards if card.is_helper)

    def card(self, key: str) -> CardSpec:
        for card in self.cards:
            if card.key == key:
                return card
        raise KeyError(key)

    def cards_for_tab(self, tab_key: str) -> list[CardSpec]:
        found = [card for card in self.cards if card.tab == tab_key]
        return sorted(found, key=lambda item: (item.layout.row, item.layout.col))  # type: ignore[union-attr]

    def parameter(self, key: str) -> ParamSpec:
        for param in self.parameters:
            if param.key == key:
                return param
        raise KeyError(key)


# ---------------------------------------------------------------------------
# Loader
# ---------------------------------------------------------------------------
def _require(mapping: dict[str, Any], key: str, where: str) -> Any:
    if key not in mapping or mapping[key] in (None, ""):
        raise SpecError(f"{where}: field '{key}' wajib diisi. {SPEC_ERROR_HINT}")
    return mapping[key]


def _as_int(value: Any, where: str) -> int:
    if not isinstance(value, int) or isinstance(value, bool):
        raise SpecError(f"{where}: harus bilangan bulat, dapat {value!r}. {SPEC_ERROR_HINT}")
    return value


def _parse_field(data: Any, where: str) -> FieldRef:
    if not isinstance(data, dict):
        raise SpecError(f"{where}.field: harus mapping {{schema, table, column}}. {SPEC_ERROR_HINT}")
    return FieldRef(
        schema=str(_require(data, "schema", f"{where}.field")),
        table=str(_require(data, "table", f"{where}.field")),
        column=str(_require(data, "column", f"{where}.field")),
    )


def _parse_filters(data: Any, where: str) -> tuple[FilterSpec, ...]:
    if data in (None, []):
        return ()
    if not isinstance(data, list):
        raise SpecError(f"{where}.filters: harus list. {SPEC_ERROR_HINT}")
    filters: list[FilterSpec] = []
    for index, item in enumerate(data):
        at = f"{where}.filters[{index}]"
        if not isinstance(item, dict):
            raise SpecError(f"{at}: harus mapping. {SPEC_ERROR_HINT}")
        filters.append(
            FilterSpec(
                tag=str(_require(item, "tag", at)),
                parameter=str(_require(item, "parameter", at)),
                label=str(item.get("label") or item["tag"]),
                field=_parse_field(_require(item, "field", at), at),
                widget=str(item["widget"]) if item.get("widget") else None,
            )
        )
    return tuple(filters)


def _parse_layout(data: Any, where: str) -> Layout | None:
    if data is None:
        return None
    if not isinstance(data, dict):
        raise SpecError(f"{where}.layout: harus mapping {{row, col, w, h}}. {SPEC_ERROR_HINT}")
    return Layout(
        row=_as_int(_require(data, "row", f"{where}.layout"), f"{where}.layout.row"),
        col=_as_int(_require(data, "col", f"{where}.layout"), f"{where}.layout.col"),
        w=_as_int(_require(data, "w", f"{where}.layout"), f"{where}.layout.w"),
        h=_as_int(_require(data, "h", f"{where}.layout"), f"{where}.layout.h"),
    )


def _parse_expect(data: Any, where: str) -> dict[str, Any]:
    if data is None:
        return {}
    if isinstance(data, dict):
        unknown = set(data) - {"value", "rows", "note"}
        if unknown:
            raise SpecError(f"{where}.expect: kunci tidak dikenal {sorted(unknown)}. {SPEC_ERROR_HINT}")
        if "value" in data and "rows" in data:
            raise SpecError(f"{where}.expect: pakai salah satu, 'value' atau 'rows'. {SPEC_ERROR_HINT}")
        return dict(data)
    return {"value": data}


def _parse_card(data: dict[str, Any], where: str) -> CardSpec:
    display = str(_require(data, "display", where))
    tab = data.get("tab")
    layout = _parse_layout(data.get("layout"), where)
    if tab and layout is None:
        raise SpecError(f"{where}: kartu bertab wajib punya 'layout'. {SPEC_ERROR_HINT}")
    if not tab and layout is not None:
        raise SpecError(f"{where}: kartu bantu (tanpa 'tab') tidak boleh punya 'layout'. {SPEC_ERROR_HINT}")
    return CardSpec(
        key=str(_require(data, "key", where)),
        name=str(_require(data, "name", where)),
        collection=str(_require(data, "collection", where)),
        display=display,
        description=str(data.get("description") or ""),
        sql=normalise_sql(str(_require(data, "sql", where))),
        filters=_parse_filters(data.get("filters"), where),
        layout=layout,
        tab=str(tab) if tab else None,
        expect=_parse_expect(data.get("expect"), where),
        visualization_settings=dict(data.get("visualization_settings") or {}),
    )


def _parse_static_list(data: Any, where: str) -> tuple[tuple[Any, str], ...]:
    if not isinstance(data, list):
        raise SpecError(f"{where}.values.static_list: harus list pasangan [nilai, label]. {SPEC_ERROR_HINT}")
    values: list[tuple[Any, str]] = []
    for index, item in enumerate(data):
        if not isinstance(item, list) or len(item) != 2:
            raise SpecError(
                f"{where}.values.static_list[{index}]: harus [nilai, label]. {SPEC_ERROR_HINT}"
            )
        values.append((item[0], str(item[1])))
    return tuple(values)


def _parse_parameter(data: dict[str, Any], where: str) -> ParamSpec:
    values = data.get("values") or {}
    if not isinstance(values, dict):
        raise SpecError(f"{where}.values: harus mapping. {SPEC_ERROR_HINT}")
    from_card = values.get("from_card")
    static = values.get("static_list")
    if bool(from_card) == bool(static):
        raise SpecError(
            f"{where}.values: pilih tepat satu, 'from_card' atau 'static_list'. {SPEC_ERROR_HINT}"
        )
    return ParamSpec(
        key=str(_require(data, "key", where)),
        name=str(_require(data, "name", where)),
        slug=str(_require(data, "slug", where)),
        type=str(_require(data, "type", where)),
        multi=bool(data.get("multi", True)),
        from_card=str(from_card) if from_card else None,
        value_field=str(_require(values, "value_field", f"{where}.values")) if from_card else None,
        static_list=_parse_static_list(static, where) if static else (),
    )


def load(iac_dir: Path) -> DashboardSpec:
    """Baca `dashboard.public.yaml` + semua `cards/*.yaml`."""
    dashboard_file = iac_dir / "dashboard.public.yaml"
    if not dashboard_file.is_file():
        raise SpecError(f"Spec dashboard tidak ditemukan: {dashboard_file}")
    raw = yaml.safe_load(dashboard_file.read_text(encoding="utf-8")) or {}

    meta = raw.get("dashboard") or {}
    where = "dashboard.public.yaml › dashboard"
    tabs = tuple(
        TabSpec(key=str(_require(item, "key", "dashboard.public.yaml › tabs")),
                name=str(_require(item, "name", "dashboard.public.yaml › tabs")))
        for item in raw.get("tabs") or []
    )
    parameters = tuple(
        _parse_parameter(item, f"dashboard.public.yaml › parameters[{index}]")
        for index, item in enumerate(raw.get("parameters") or [])
    )

    cards: list[CardSpec] = []
    for path in sorted((iac_dir / "cards").glob("*.yaml")):
        document = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
        for index, item in enumerate(document.get("cards") or []):
            cards.append(_parse_card(item, f"{path.name} › cards[{index}]"))

    return DashboardSpec(
        database=str(_require(meta, "database", where)),
        name=str(_require(meta, "name", where)),
        description=str(meta.get("description") or ""),
        width=str(meta.get("width") or "fixed"),
        auto_apply_filters=bool(meta.get("auto_apply_filters", True)),
        public_link=bool(meta.get("public_link", True)),
        tabs=tabs,
        parameters=parameters,
        cards=tuple(cards),
    )


# ---------------------------------------------------------------------------
# Validasi tata letak (dipakai validate.py dan verify.py)
# ---------------------------------------------------------------------------
def layout_problems(cards: Iterable[CardSpec]) -> list[str]:
    """Cek grid: tidak bertumpuk, tidak melewati 24 kolom, tidak ada celah baris.

    Metabase menggeser kartu yang bertumpuk saat render, tetapi tinggi dashboard
    (posisi footer "Didukung oleh Metabase") tetap dihitung dari `row` deklaratif.
    Akibatnya kartu terakhir bisa menembus footer. Karena itu tumpang tindih
    diperlakukan sebagai error, bukan peringatan.
    """
    problems: list[str] = []
    by_tab: dict[str, list[CardSpec]] = {}
    for card in cards:
        if card.tab and card.layout:
            by_tab.setdefault(card.tab, []).append(card)

    for tab_key, items in by_tab.items():
        cells: dict[tuple[int, int], str] = {}
        reported: set[tuple[str, str]] = set()
        for card in items:
            grid = card.layout
            assert grid is not None
            if grid.col + grid.w > GRID_COLUMNS:
                problems.append(
                    f"{card.key}: col {grid.col} + lebar {grid.w} melewati {GRID_COLUMNS} kolom"
                )
            if grid.w <= 0 or grid.h <= 0:
                problems.append(f"{card.key}: ukuran grid tidak valid ({grid.w}x{grid.h})")
            for row in range(grid.row, grid.row + grid.h):
                for col in range(grid.col, grid.col + grid.w):
                    other = cells.get((row, col))
                    if other and (card.key, other) not in reported:
                        reported.add((card.key, other))
                        problems.append(
                            f"{card.key} bertumpuk dengan {other} di tab {tab_key} "
                            f"(mulai row {row}, col {col})"
                        )
                    cells[(row, col)] = card.key
    return problems


# ---------------------------------------------------------------------------
# Emit YAML (dipakai export.py) — blok `|` untuk SQL, flow map untuk layout
# ---------------------------------------------------------------------------
class Literal(str):
    """String yang dicetak sebagai blok literal `|` agar SQL tetap terbaca."""


class FlowMap(dict):
    """Mapping yang dicetak satu baris (`{row: 0, col: 0, w: 4, h: 4}`)."""


class FlowList(list):
    """List yang dicetak satu baris (`[1, 'Negeri']`)."""


class Dumper(yaml.SafeDumper):
    def increase_indent(self, flow: bool = False, indentless: bool = False):  # noqa: D102
        return super().increase_indent(flow, False)


def _represent_literal(dumper: yaml.SafeDumper, data: Literal):
    return dumper.represent_scalar("tag:yaml.org,2002:str", str(data), style="|")


def _represent_flow(dumper: yaml.SafeDumper, data: FlowMap):
    return dumper.represent_mapping("tag:yaml.org,2002:map", data, flow_style=True)


def _represent_flow_list(dumper: yaml.SafeDumper, data: FlowList):
    return dumper.represent_sequence("tag:yaml.org,2002:seq", data, flow_style=True)


Dumper.add_representer(Literal, _represent_literal)
Dumper.add_representer(FlowMap, _represent_flow)
Dumper.add_representer(FlowList, _represent_flow_list)


def dump(document: dict[str, Any]) -> str:
    return yaml.dump(
        document,
        Dumper=Dumper,
        sort_keys=False,
        allow_unicode=True,
        default_flow_style=False,
        width=4096,
    )
