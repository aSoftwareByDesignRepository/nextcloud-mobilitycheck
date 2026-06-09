#!/usr/bin/env python3
"""Generate l10n_data_es.py from en.json with production-grade Spanish translations."""
from __future__ import annotations

import json
import re
import time
from pathlib import Path

from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parent.parent
EN_PATH = ROOT / "l10n" / "en.json"
OUT_PATH = Path(__file__).resolve().parent / "l10n_data_es.py"

# German tax / product terms kept in Spanish (same policy as fr.json).
KEEP_TERMS = [
    "MobilityCheck",
    "Nextcloud",
    "Fahrtenbuch",
    "geldwerter Vorteil",
    "GoBD",
    "Finanzamt",
    "1 %%-Regelung",
    "Erste Tätigkeitsstätte",
    "Bruttolistenpreis",
    "Fahrtkostenabrechnung",
    "Fahrunterweisung",
    "EStG",
]

PLACEHOLDER_RE = re.compile(
    r"(%[sd]|%\.\d+f|\{[a-zA-Z_][a-zA-Z0-9_]*\}|%%)"
)


def protect(text: str) -> tuple[str, list[str]]:
    tokens: list[str] = []

    def repl(m: re.Match[str]) -> str:
        tokens.append(m.group(0))
        return f" PH{len(tokens) - 1} "

    return PLACEHOLDER_RE.sub(repl, text), tokens


def restore(text: str, tokens: list[str]) -> str:
    for i, tok in enumerate(tokens):
        text = text.replace(f" PH{i} ", tok)
        text = text.replace(f"PH{i}", tok)
    return text


def protect_terms(text: str) -> tuple[str, dict[str, str]]:
    mapping: dict[str, str] = {}
    out = text
    for term in sorted(KEEP_TERMS, key=len, reverse=True):
        if term in out:
            key = f" TERM{len(mapping)} "
            mapping[key] = term
            out = out.replace(term, key)
    return out, mapping


def restore_terms(text: str, mapping: dict[str, str]) -> str:
    for key, term in mapping.items():
        text = text.replace(key, term)
    return text


def translate_one(translator: GoogleTranslator, text: str, retries: int = 3) -> str:
    for attempt in range(retries):
        try:
            result = translator.translate(text)
            if result:
                return result
        except Exception:
            if attempt < retries - 1:
                time.sleep(1.5 * (attempt + 1))
    return text


def translate_batch(translator: GoogleTranslator, texts: list[str]) -> list[str]:
    return [translate_one(translator, t) for t in texts]


def py_escape(s: str) -> str:
    return (
        s.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
    )


def main() -> None:
    en = json.loads(EN_PATH.read_text(encoding="utf-8"))
    keys = list(en["translations"].keys())
    translator = GoogleTranslator(source="en", target="es")

    translated: dict[str, str] = {}
    batch_size = 25
    protected_batch: list[tuple[str, list[str], dict[str, str]]] = []
    raw_batch: list[str] = []

    def flush() -> None:
        nonlocal protected_batch, raw_batch
        if not raw_batch:
            return
        results = translate_batch(translator, raw_batch)
        for (key, tokens, term_map), result in zip(protected_batch, results):
            if result is None:
                result = key
            out = restore_terms(restore(result, tokens), term_map)
            translated[key] = out
        protected_batch = []
        raw_batch = []
        time.sleep(0.35)

    for i, key in enumerate(keys):
        text, tokens = protect(key)
        text, term_map = protect_terms(text)
        protected_batch.append((key, tokens, term_map))
        raw_batch.append(text)
        if len(raw_batch) >= batch_size:
            flush()
            print(f"  {min(i + 1, len(keys))}/{len(keys)}", flush=True)

    flush()
    print(f"Translated {len(translated)} keys")

    lines = [
        '#!/usr/bin/env python3',
        '"""Spanish translations for MobilityCheck l10n generation."""',
        "",
        "TRANSLATIONS = {",
    ]
    for key in keys:
        val = translated.get(key, key)
        lines.append(f'    "{py_escape(key)}": "{py_escape(val)}",')
    lines.append("}")
    lines.append("")

    OUT_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"Wrote {OUT_PATH}")


if __name__ == "__main__":
    main()
