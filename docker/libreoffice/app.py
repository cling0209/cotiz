#!/usr/bin/env python3
"""HTTP sidecar: convierte Office a PDF con LibreOffice headless."""

from __future__ import annotations

import os
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ALLOWED_EXT = {".doc", ".docx", ".xls", ".xlsx", ".odt", ".ods"}
MAX_BYTES = 25 * 1024 * 1024
CONVERT_TIMEOUT = int(os.environ.get("LIBREOFFICE_CONVERT_TIMEOUT", "90"))
LOCK = threading.Lock()
PORT = int(os.environ.get("PORT", "8080"))


def _extension(nombre: str) -> str:
    return Path(nombre).suffix.lower()


def convertir(contenido: bytes, nombre: str) -> bytes:
    ext = _extension(nombre)
    if ext not in ALLOWED_EXT:
        raise ValueError("Tipo de archivo no soportado.")
    if not contenido or len(contenido) > MAX_BYTES:
        raise ValueError("Archivo vacío o demasiado grande.")

    with tempfile.TemporaryDirectory(prefix="lo-") as td:
        src = Path(td) / f"entrada{ext}"
        src.write_bytes(contenido)
        profile = Path(td) / "profile"
        profile.mkdir()
        env = os.environ.copy()
        env["HOME"] = td
        env["SAL_USE_VCLPLUGIN"] = "svp"
        uri = profile.resolve().as_posix()
        cmd = [
            "soffice",
            "--headless",
            "--nologo",
            "--nofirststartwizard",
            "--norestore",
            f"-env:UserInstallation=file://{uri}",
            "--convert-to",
            "pdf",
            "--outdir",
            td,
            str(src),
        ]
        proc = subprocess.run(
            cmd,
            env=env,
            capture_output=True,
            timeout=CONVERT_TIMEOUT,
            check=False,
        )
        pdfs = list(Path(td).glob("*.pdf"))
        if proc.returncode != 0 or not pdfs:
            detalle = (proc.stderr or proc.stdout or b"").decode("utf-8", "replace")[-800:]
            raise RuntimeError(detalle or "LibreOffice no generó PDF.")
        return pdfs[0].read_bytes()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:  # noqa: A003
        print(f"[libreoffice] {self.address_string()} {fmt % args}", flush=True)

    def do_GET(self) -> None:  # noqa: N802
        if self.path.split("?", 1)[0] != "/health":
            self.send_error(404)
            return
        body = b'{"status":"ok"}'
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self) -> None:  # noqa: N802
        if self.path.split("?", 1)[0] != "/convert":
            self.send_error(404)
            return
        length = int(self.headers.get("Content-Length", "0") or "0")
        if length <= 0 or length > MAX_BYTES:
            self._error(400, "Cuerpo inválido.")
            return
        nombre = (self.headers.get("X-Filename") or "archivo.bin").strip() or "archivo.bin"
        contenido = self.rfile.read(length)
        try:
            with LOCK:
                pdf = convertir(contenido, nombre)
        except ValueError as exc:
            self._error(400, str(exc))
            return
        except subprocess.TimeoutExpired:
            self._error(504, "La conversión tardó demasiado.")
            return
        except Exception as exc:  # noqa: BLE001
            self._error(422, str(exc)[:500])
            return
        self.send_response(200)
        self.send_header("Content-Type", "application/pdf")
        self.send_header("Content-Length", str(len(pdf)))
        self.end_headers()
        self.wfile.write(pdf)

    def _error(self, code: int, mensaje: str) -> None:
        body = mensaje.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def main() -> None:
    httpd = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    print(f"[libreoffice] listening on {PORT}", flush=True)
    httpd.serve_forever()


if __name__ == "__main__":
    main()
