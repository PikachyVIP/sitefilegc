#!/usr/bin/env python3
"""Run one Transmute/GC converter pair without its FastAPI database layer.

The PHP worker calls this file as a subprocess.  It deliberately speaks JSON on
stdout and keeps every human-readable message on stderr so a converter that
prints a progress line cannot corrupt the result.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import sys
import zipfile
from pathlib import Path


def _backend_dir() -> Path:
    configured = os.environ.get("GC_CONVERTER_BACKEND", "").strip()
    candidates = [
        Path(configured) if configured else None,
        Path(__file__).resolve().parent / "converter_py",
    ]
    for candidate in candidates:
        if candidate is not None and (candidate / "registry" / "registry.py").is_file():
            return candidate
    raise SystemExit(
        "GC_CONVERTER_BACKEND is not set and no converter_py/backend directory was found"
    )


def _parse() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--input-format", required=True)
    parser.add_argument("--output-format", required=True)
    parser.add_argument("--converter", default="")
    parser.add_argument("--quality", default="")
    parser.add_argument("--result", default="")
    return parser.parse_args()


def main() -> int:
    args = _parse()
    backend = _backend_dir()
    sys.path.insert(0, str(backend))

    # WeasyPrint (and a few optional modules) load native libraries at import
    # time.  The email converter only needs them for HTML/PDF output; a stub
    # keeps the registry importable on hosts where GTK is absent.
    try:
        import weasyprint  # noqa: F401
    except Exception:
        import types

        stub = types.ModuleType("weasyprint")

        class _HTML:
            def __init__(self, *a, **k):
                pass

            def write_pdf(self, *a, **k):
                raise RuntimeError("WeasyPrint is not installed")

        stub.HTML = _HTML
        sys.modules["weasyprint"] = stub

    from registry.registry import ConverterRegistry

    source = Path(args.input)
    if not source.is_file():
        raise SystemExit("input file is missing")
    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    registry = ConverterRegistry(skip_unregisterable=False)
    candidates = []
    named = registry.converters.get(args.converter) if args.converter else None
    preferred = registry.get_converter_for_conversion(
        args.input_format, args.output_format
    )
    for converter_class in (named, preferred):
        if converter_class is not None and converter_class not in candidates:
            candidates.append(converter_class)
    for converter_class in registry.converters.values():
        if converter_class not in candidates:
            candidates.append(converter_class)

    quality = args.quality or None
    produced: list[Path] = []
    errors: list[str] = []
    for converter_class in candidates:
        try:
            converter = converter_class(
                input_file=str(source),
                output_dir=str(output_dir),
                input_type=args.input_format,
                output_type=args.output_format,
            )
            if not converter.can_convert():
                continue
            generated = [Path(p) for p in converter.convert(quality=quality)]
            produced = [
                p for p in generated if p.is_file() and p.stat().st_size > 0
            ]
            if produced:
                break
        except Exception as exc:  # noqa: BLE001 - try the next compatible class
            errors.append(f"{converter_class.__name__}: {type(exc).__name__}: {exc}")
    if not produced:
        detail = "; ".join(errors[-3:])
        raise SystemExit(
            f"no converter produced {args.input_format} -> {args.output_format}"
            + (f": {detail}" if detail else "")
        )

    safe_format = args.output_format.replace("/", "-")
    produced.sort()
    if len(produced) == 1:
        result = output_dir / ("output." + safe_format)
        if produced[0] != result:
            shutil.move(str(produced[0]), result)
        payload = {"output": result.name, "size": result.stat().st_size}
    else:
        result = output_dir / "output.zip"
        with zipfile.ZipFile(result, "w", zipfile.ZIP_DEFLATED) as archive:
            for index, path in enumerate(produced, 1):
                archive.write(path, arcname=path.name or f"output-{index}.{safe_format}")
        payload = {"output": result.name, "size": result.stat().st_size, "files": len(produced)}

    if args.result:
        tmp = Path(args.result + ".tmp")
        tmp.write_text(json.dumps(payload), encoding="utf-8")
        tmp.replace(args.result)
    else:
        json.dump(payload, sys.stdout)
        sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except SystemExit:
        raise
    except Exception as exc:  # noqa: BLE001 - subprocess boundary
        print(f"{type(exc).__name__}: {exc}", file=sys.stderr)
        raise SystemExit(1)
