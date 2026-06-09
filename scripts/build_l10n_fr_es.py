#!/usr/bin/env python3
"""Generate l10n/fr.json and l10n/es.json from embedded production translations."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
L10N = ROOT / "l10n"

PLURAL_FORMS = {
    "fr": "nplurals=3; plural=(n == 0 || n == 1) ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;",
    "es": "nplurals=3; plural=n == 1 ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;",
}

# Translations loaded from companion data files (generated below).
from l10n_data_fr import TRANSLATIONS as FR  # type: ignore
from l10n_data_es import TRANSLATIONS as ES  # type: ignore


def main() -> None:
    en = json.loads((L10N / "en.json").read_text(encoding="utf-8"))
    en_keys = set(en["translations"].keys())

    for lang, trans in [("fr", FR), ("es", ES)]:
        missing = en_keys - set(trans.keys())
        extra = set(trans.keys()) - en_keys
        if missing:
            raise SystemExit(f"{lang}: missing {len(missing)} keys, e.g. {list(missing)[:3]}")
        if extra:
            raise SystemExit(f"{lang}: extra {len(extra)} keys, e.g. {list(extra)[:3]}")

        out = {
            "pluralForm": PLURAL_FORMS[lang],
            "translations": {k: trans[k] for k in en["translations"]},
        }
        path = L10N / f"{lang}.json"
        path.write_text(json.dumps(out, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
        print(f"Wrote {path} ({len(trans)} keys)")


if __name__ == "__main__":
    main()
