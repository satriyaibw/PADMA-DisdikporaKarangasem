#!/usr/bin/env python3
"""Klien tipis Metabase API untuk toolchain IaC dashboard publik.

Hanya memakai standard library (`urllib`), supaya bisa dijalankan di mesin target
mana pun yang punya Python 3.10+ tanpa memasang SDK.

Kredensial dicari dengan urutan:
1. argumen `--url` / variabel lingkungan `MB_URL`, `MB_EMAIL`, `MB_PASSWORD`
2. nama lama yang sudah dipakai repo ini: `METABASE_ADMIN_EMAIL`,
   `METABASE_ADMIN_PASSWORD`, `METABASE_URL`, `METABASE_INTERNAL_URL`
3. file env (`--env-file`, `MB_ENV_FILE`, `.env` di direktori kerja, atau
   `dashboard-karangasem/.env` bila tool dijalankan dari dalam repo)

Semua kandidat URL dicoba satu per satu lewat `GET /api/health`, sehingga skrip
tetap jalan baik dari host (`http://localhost:3000`) maupun dari dalam Docker
(`http://metabase:3000`).
"""
from __future__ import annotations

import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any


class MetabaseError(RuntimeError):
    """Kesalahan HTTP dari Metabase, dengan pesan yang bisa dibaca operator."""

    def __init__(self, method: str, path: str, status: int, body: str) -> None:
        self.method = method
        self.path = path
        self.status = status
        self.body = body
        super().__init__(f"{method} {path} -> HTTP {status}: {body[:600]}")


class Metabase:
    """Sesi Metabase (header `X-Metabase-Session`)."""

    def __init__(self, base: str, session: str, timeout: int = 300) -> None:
        self.base = base.rstrip("/")
        self.session = session
        self.timeout = timeout

    # -- HTTP primitives ---------------------------------------------------
    def request(
        self,
        method: str,
        path: str,
        body: Any = None,
        query: dict[str, str] | None = None,
        retries: int = 1,
    ) -> Any:
        url = self.base + path
        if query:
            url += "?" + urllib.parse.urlencode(query)
        payload = json.dumps(body).encode() if body is not None else None
        headers = {"Content-Type": "application/json"}
        if self.session:
            headers["X-Metabase-Session"] = self.session

        last_error: Exception | None = None
        for attempt in range(retries + 1):
            request = urllib.request.Request(url, data=payload, method=method, headers=headers)
            try:
                with urllib.request.urlopen(request, timeout=self.timeout) as response:
                    raw = response.read()
                    return json.loads(raw) if raw else None
            except urllib.error.HTTPError as exc:
                detail = exc.read().decode("utf-8", "replace")
                # 5xx bisa transien (Metabase sedang sync metadata) -> ulangi sekali.
                if exc.code >= 500 and attempt < retries:
                    last_error = MetabaseError(method, path, exc.code, detail)
                    time.sleep(2)
                    continue
                raise MetabaseError(method, path, exc.code, detail) from None
            except (urllib.error.URLError, TimeoutError) as exc:
                if attempt < retries:
                    last_error = exc
                    time.sleep(2)
                    continue
                raise MetabaseError(method, path, 0, str(exc)) from None
        raise MetabaseError(method, path, 0, str(last_error))

    def get(self, path: str, **kwargs: Any) -> Any:
        return self.request("GET", path, **kwargs)

    def post(self, path: str, body: Any = None, **kwargs: Any) -> Any:
        return self.request("POST", path, body=body, **kwargs)

    def put(self, path: str, body: Any = None, **kwargs: Any) -> Any:
        return self.request("PUT", path, body=body, **kwargs)


# ---------------------------------------------------------------------------
# Konfigurasi koneksi
# ---------------------------------------------------------------------------
def read_env_file(path: Path) -> dict[str, str]:
    """Baca `KEY=VALUE` sederhana (komentar `#` diabaikan)."""
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


def default_env_file() -> Path | None:
    """Cari file env yang wajar tanpa mengikat tool ke path repo."""
    candidates: list[Path] = []
    if os.environ.get("MB_ENV_FILE"):
        candidates.append(Path(os.environ["MB_ENV_FILE"]))
    candidates.append(Path.cwd() / ".env")
    # Konvensi repo ini: kredensial Metabase ada di dashboard-karangasem/.env.
    iac_dir = Path(__file__).resolve().parents[1]
    candidates.append(iac_dir.parents[2] / "dashboard-karangasem" / ".env")
    for candidate in candidates:
        if candidate.is_file():
            return candidate
    return None


def credentials(env: dict[str, str]) -> tuple[str, str]:
    email = env.get("MB_EMAIL") or env.get("METABASE_ADMIN_EMAIL")
    password = env.get("MB_PASSWORD") or env.get("METABASE_ADMIN_PASSWORD")
    if not email or not password:
        raise SystemExit(
            "Kredensial Metabase tidak ditemukan.\n"
            "Set MB_EMAIL & MB_PASSWORD (atau METABASE_ADMIN_EMAIL & "
            "METABASE_ADMIN_PASSWORD), atau lewati --env-file <path>."
        )
    return email, password


def candidate_urls(explicit: str | None, env: dict[str, str]) -> list[str]:
    raw = [
        explicit,
        env.get("MB_URL"),
        env.get("METABASE_URL"),
        env.get("METABASE_INTERNAL_URL"),
        "http://localhost:3000",
        "http://127.0.0.1:3000",
    ]
    result: list[str] = []
    for item in raw:
        if not item:
            continue
        url = item.rstrip("/")
        if url not in result:
            result.append(url)
    return result


def connect(
    url: str | None = None,
    env_file: str | Path | None = None,
) -> tuple[Metabase, str, Path | None]:
    """Login ke Metabase. Mengembalikan (klien, base_url, path_env_file)."""
    env_path = Path(env_file) if env_file else default_env_file()
    env = read_env_file(env_path) if env_path else {}
    env.update({key: value for key, value in os.environ.items() if key.startswith(("MB_", "METABASE_"))})
    email, password = credentials(env)

    errors: list[str] = []
    for base in candidate_urls(url, env):
        try:
            with urllib.request.urlopen(base + "/api/health", timeout=10) as response:
                if response.status != 200:
                    errors.append(f"{base} (health HTTP {response.status})")
                    continue
        except (urllib.error.URLError, OSError, TimeoutError) as exc:
            errors.append(f"{base} ({exc})")
            continue
        try:
            session = Metabase(base, session="").post(
                "/api/session", {"username": email, "password": password}
            )["id"]
        except MetabaseError as exc:
            errors.append(f"{base} (login gagal: {exc})")
            continue
        except KeyError:
            errors.append(f"{base} (respons login tanpa 'id')")
            continue
        return Metabase(base, session=session), base, env_path

    raise SystemExit("Tidak bisa terhubung ke Metabase. Kandidat:\n  - " + "\n  - ".join(errors))


def say(message: str) -> None:
    """Cetak ke stderr supaya stdout tetap bersih untuk pipeline."""
    print(message, file=sys.stderr)
