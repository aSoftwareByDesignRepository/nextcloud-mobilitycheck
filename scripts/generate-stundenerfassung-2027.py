#!/usr/bin/env python3
"""Generate realistic side-project hour logs (June–November 2027) — MobilityCheck.

- Phases with more/less work per month
- Occasional Sundays (nebenberuflich)
- Demo 27.11. Berlin: Hauke + Lara vor Ort; Alex krank (25.–27.11.)
- Urlaub/freie Tage pro Person
"""
from __future__ import annotations

import csv
import random
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path

random.seed(20270601)

YEAR = 2027
START = date(YEAR, 6, 1)
END = date(YEAR, 11, 30)

TARGETS = {
    "Hauke Klünder": 248.0,
    "Alexander Mäule": 256.0,
    "Lara Raffel": 438.0,
}

WEEKLY_CAP = {
    "Hauke Klünder": 9.5,
    "Alexander Mäule": 10.0,
    "Lara Raffel": 16.5,
}

DAILY_CAP = 10.0
HOLIDAYS = {date(YEAR, 10, 3)}

# Monats-Intensität (Nebenprojekt: Spitzen + ruhige Phasen); wird normalisiert
MONTH_INTENSITY = {
    "Hauke Klünder": {6: 0.75, 7: 1.05, 8: 0.65, 9: 1.0, 10: 1.15, 11: 1.1},
    "Alexander Mäule": {6: 0.9, 7: 1.1, 8: 0.7, 9: 1.05, 10: 1.2, 11: 0.95},
    "Lara Raffel": {6: 0.85, 7: 1.15, 8: 0.8, 9: 1.1, 10: 1.25, 11: 1.0},
}

MONTH_AP = {
    6: {"Hauke Klünder": "AP1", "Alexander Mäule": "AP2", "Lara Raffel": "AP2"},
    7: {"Hauke Klünder": "AP1", "Alexander Mäule": "AP3", "Lara Raffel": "AP3"},
    8: {"Hauke Klünder": "AP1", "Alexander Mäule": "AP4", "Lara Raffel": "AP4"},
    9: {"Hauke Klünder": "AP1", "Alexander Mäule": "AP4", "Lara Raffel": "AP5"},
    10: {"Hauke Klünder": "AP1", "Alexander Mäule": "AP6", "Lara Raffel": "AP7"},
    11: {"Hauke Klünder": "AP1", "Alexander Mäule": "AP7", "Lara Raffel": "AP7"},
}

ACTIVITY = {
    "AP1": "Projektleitung, QA, Förderkommunikation, Tests",
    "AP2": "Architektur, mc_*-Datenmodell, Zugriffskontrolle, CI",
    "AP3": "Buchungs-Engine, Konfliktprüfung, Freigaben, REST",
    "AP4": "Fahrer-Compliance, Checkout/Check-in, Security",
    "AP5": "Schäden, Kosten, Wartung, Euro-Cent-Logik",
    "AP6": "Fahrtenbuch, Erstattung, Exporte, Benachrichtigungen",
    "AP7": "Release, Doku, Barrierefreiheit, i18n, Verwertung",
}

CHUNK = {
    "Hauke Klünder": [1.5, 2.0, 2.5, 3.0, 3.5],
    "Alexander Mäule": [2.0, 2.5, 3.0, 3.5, 4.0, 4.5],
    "Lara Raffel": [2.0, 2.5, 3.0, 4.0, 4.5, 5.0, 5.5, 6.0],
}

DEMO_BERLIN = date(YEAR, 11, 27)

# Urlaub, frei, Krankheit — keine Projektstunden
PERSON_ABSENCE_RANGES: dict[str, list[tuple[date, date, str]]] = {
    "Alexander Mäule": [
        (date(YEAR, 6, 20), date(YEAR, 6, 28), "Urlaub (9 Tage, Ende Juni)"),
        (date(YEAR, 9, 1), date(YEAR, 9, 7), "Urlaub (erste Septemberwoche)"),
        (date(YEAR, 11, 25), date(YEAR, 11, 27), "krank (Abschluss-Demo Berlin entfällt)"),
    ],
    "Lara Raffel": [
        (date(YEAR, 7, 11), date(YEAR, 7, 22), "Urlaub / frei (Juli)"),
    ],
    "Hauke Klünder": [
        (date(YEAR, 6, 29), date(YEAR, 7, 7), "Urlaub (Ende Juni / Anfang Juli)"),
        (date(YEAR, 8, 3), date(YEAR, 8, 10), "Urlaub (August)"),
        (date(YEAR, 9, 1), date(YEAR, 9, 7), "Urlaub (erste Septemberwoche)"),
    ],
}


def round_half(x: float) -> float:
    return round(x * 2) / 2


def week_key(d: date) -> tuple[int, int]:
    iso = d.isocalendar()
    return (iso.year, iso.week)


def month_has_absence(person: str, month: int) -> bool:
    for start, end, _reason in PERSON_ABSENCE_RANGES.get(person, []):
        if start.month <= month <= end.month or start.month == month or end.month == month:
            return True
    return False


def is_blocked(person: str, d: date) -> bool:
    if d in HOLIDAYS:
        return True
    for start, end, _reason in PERSON_ABSENCE_RANGES.get(person, []):
        if start <= d <= end:
            return True
    return False


def calendar_days(month: int) -> list[date]:
    """Mo–Sa + Sonntage (für gelegentliche Nebenprojekt-Tage)."""
    out: list[date] = []
    d = date(YEAR, month, 1)
    while d.month == month and d <= END:
        if d >= START and d not in HOLIDAYS:
            out.append(d)
        d += timedelta(days=1)
    return out


def month_target(person: str) -> dict[int, float]:
    weights = MONTH_INTENSITY[person]
    s = sum(weights.values())
    raw = {m: TARGETS[person] * (weights[m] / s) for m in weights}
    rounded = {m: round_half(raw[m]) for m in raw}
    diff = round_half(TARGETS[person] - sum(rounded.values()))
    rounded[11] = round_half(rounded[11] + diff)
    return rounded


def day_weight(d: date, person: str) -> float:
    """Bevorzugung Werktage; Sonntag selten; Samstag moderat."""
    if d.weekday() == 6:
        return 0.12
    if d.weekday() == 5:
        return 0.45
    if d.weekday() == 0:
        return 0.55
    return 1.0


def activity_note(person: str, ap: str, d: date) -> str:
    note = ACTIVITY[ap]
    if d == DEMO_BERLIN:
        if person in ("Hauke Klünder", "Lara Raffel"):
            note += "; Abschluss-Demo Berlin (vor Ort)"
        return note
    if d >= date(YEAR, 11, 20) and person == "Hauke Klünder":
        note += "; Schlussbericht, Verwendungsnachweis"
    elif d >= date(YEAR, 11, 20) and person != "Hauke Klünder":
        note += "; Schlussdoku, Patches"
    elif d >= date(YEAR, 10, 20):
        note += "; App-Store, Release-Nacharbeit"
    elif d >= date(YEAR, 10, 12) and ap in ("AP6", "AP7"):
        note += "; Release-Kandidat, Signierung"
    elif d.month == 8 and random.random() < 0.15:
        note += "; ruhigere Phase (Sommer)"
    elif random.random() < 0.07:
        note += "; Review, Pilotabstimmung Fuhrpark"
    return note


def week_room(person: str, d: date, week_used: dict, *, soft: bool = False) -> float:
    cap = WEEKLY_CAP[person] + (1.5 if soft else 0.0)
    return cap - week_used[(person, week_key(d))]


def can_add(person: str, d: date, hours: float, week_used: dict, *, soft: bool = False) -> bool:
    if hours > DAILY_CAP:
        return False
    return week_room(person, d, week_used, soft=soft) >= hours - 0.001


def add_entry(
    entries: list[dict],
    person: str,
    d: date,
    hours: float,
    week_used: dict,
    *,
    taetigkeit: str | None = None,
    soft_week: bool = False,
) -> bool:
    hours = round_half(hours)
    if hours < 1.0 or not can_add(person, d, hours, week_used, soft=soft_week):
        return False
    ap = MONTH_AP[d.month][person]
    entries.append(
        {
            "datum": d.isoformat(),
            "person": person,
            "stunden": hours,
            "ap": ap,
            "taetigkeit": taetigkeit or activity_note(person, ap, d),
        }
    )
    week_used[(person, week_key(d))] += hours
    return True


def pick_days_for_month(person: str, month: int, budget: float) -> list[date]:
    """Weniger gleichmäßig: manche Wochen dicht, manche Lücken."""
    pool = calendar_days(month)
    weighted = sorted(pool, key=lambda d: day_weight(d, person) * random.random(), reverse=True)

    if person == "Lara Raffel":
        avg = 4.0
    elif person == "Alexander Mäule":
        avg = 3.2
    else:
        avg = 2.6

    # Nach Urlaub im Monat: etwas mehr Arbeitstage in den verbleibenden Wochen
    blocked_in_month = sum(1 for d in pool if is_blocked(person, d))
    extra = 2 if blocked_in_month >= 5 else 0
    n = max(5, min(len(pool) - blocked_in_month, int(budget / avg) + random.randint(-1, 3) + extra))
    chosen: list[date] = []
    weeks_used: dict[tuple, int] = defaultdict(int)

    for d in weighted:
        if len(chosen) >= n:
            break
        if is_blocked(person, d):
            continue
        wk = week_key(d)
        if weeks_used[(person, wk)] >= 3:
            continue
        if d.weekday() == 6 and sum(1 for x in chosen if x.weekday() == 6) >= 1:
            continue
        if d.weekday() == 5 and sum(1 for x in chosen if x.weekday() == 5) >= 2:
            continue
        chosen.append(d)
        weeks_used[(person, wk)] += 1

    return chosen


def apply_demo_berlin(entries: list[dict], week_used: dict) -> None:
    """Hauke + Lara vor Ort am 27.11.; Alex nicht in Berlin."""
    demo_hours = {"Hauke Klünder": 6.0, "Lara Raffel": 7.0}

    for person, hours in demo_hours.items():
        wk = week_key(DEMO_BERLIN)
        for e in entries:
            if e["person"] == person and e["datum"] == DEMO_BERLIN.isoformat():
                week_used[(person, wk)] -= e["stunden"]
                entries.remove(e)
                break

        need = hours
        week_entries = [
            e
            for e in entries
            if e["person"] == person and week_key(date.fromisoformat(e["datum"])) == wk
        ]
        for e in sorted(week_entries, key=lambda x: x["stunden"]):
            if need <= WEEKLY_CAP[person] - sum(
                x["stunden"]
                for x in entries
                if x["person"] == person and week_key(date.fromisoformat(x["datum"])) == wk
            ):
                break
            cut = min(need, round_half(e["stunden"] - 1.0))
            if cut <= 0:
                continue
            e["stunden"] = round_half(e["stunden"] - cut)
            week_used[(person, wk)] -= cut
            need = round_half(need - cut)

        ap = MONTH_AP[11][person]
        add_entry(
            entries,
            person,
            DEMO_BERLIN,
            hours,
            week_used,
            taetigkeit=ACTIVITY[ap] + "; Abschluss-Demo Berlin (vor Ort)",
        )

    strip_blocked_days(entries, week_used)


def strip_blocked_days(entries: list[dict], week_used: dict) -> None:
    for e in list(entries):
        person = e["person"]
        d = date.fromisoformat(e["datum"])
        if is_blocked(person, d):
            week_used[(person, week_key(d))] -= e["stunden"]
            entries.remove(e)


def top_up_to_target(entries: list[dict], week_used: dict) -> None:
    """Stunden auf Ziel bringen, wenn Wochenlimits viel Urlaub blockieren."""
    all_days = [d for m in range(6, 12) for d in calendar_days(m)]

    def total(person: str) -> float:
        return sum(e["stunden"] for e in entries if e["person"] == person)

    for person, target in TARGETS.items():
        gap = round_half(target - total(person))
        used = {e["datum"] for e in entries if e["person"] == person}
        days = [d for d in all_days if not is_blocked(person, d)]
        days.sort(key=lambda d: (month_has_absence(person, d.month), random.random()))

        for d in days:
            if gap < 0.5:
                break
            if d.isoformat() in used:
                e = next(x for x in entries if x["person"] == person and x["datum"] == d.isoformat())
                add = min(
                    gap,
                    2.0,
                    DAILY_CAP - e["stunden"],
                    week_room(person, d, week_used, soft=True),
                )
                add = round_half(add)
                if add >= 0.5:
                    e["stunden"] = round_half(e["stunden"] + add)
                    week_used[(person, week_key(d))] += add
                    gap = round_half(gap - add)
                continue
            for h in (4.5, 4.0, 3.5, 3.0, 2.5, 2.0, 1.5):
                if h > gap:
                    continue
                if add_entry(entries, person, d, h, week_used, soft_week=True):
                    gap = round_half(gap - h)
                    used.add(d.isoformat())
                    break


def fill_to_target(entries: list[dict], week_used: dict) -> None:
    def total(person: str) -> float:
        return sum(e["stunden"] for e in entries if e["person"] == person)

    for person, target in TARGETS.items():
        gap = round_half(target - total(person))
        pool = [d for m in range(6, 12) for d in calendar_days(m)]
        used = {e["datum"] for e in entries if e["person"] == person}
        pool.sort(
            key=lambda d: (
                not month_has_absence(person, d.month),
                10 <= d.month <= 11,
                random.random(),
            )
        )
        for d in pool:
            if gap < 0.5:
                break
            if is_blocked(person, d):
                continue
            if d.isoformat() in used:
                continue
            for h in (4.0, 3.5, 3.0, 2.5, 2.0, 1.5):
                if h > gap:
                    continue
                if add_entry(entries, person, d, h, week_used):
                    gap = round_half(gap - h)
                    used.add(d.isoformat())
                    break

        gap = round_half(target - total(person))
        for e in reversed([x for x in entries if x["person"] == person]):
            if gap < 0.5:
                break
            d = date.fromisoformat(e["datum"])
            add = min(gap, 1.5, DAILY_CAP - e["stunden"], WEEKLY_CAP[person] - week_used[(person, week_key(d))])
            add = round_half(add)
            if add < 0.5:
                continue
            e["stunden"] = round_half(e["stunden"] + add)
            week_used[(person, week_key(d))] += add
            gap = round_half(gap - add)


def generate() -> list[dict]:
    entries: list[dict] = []
    week_used: dict = defaultdict(float)

    for person in TARGETS:
        targets = month_target(person)
        for month in range(6, 12):
            budget = targets[month]
            days = pick_days_for_month(person, month, budget)
            if not days:
                continue
            per_day = budget / len(days)
            for d in days:
                h = per_day + random.choice([-0.5, 0, 0, 0.5])
                if d.weekday() == 6:
                    h = min(h, 4.0)
                h = min(h, random.choice(CHUNK[person]), budget)
                if not add_entry(entries, person, d, h, week_used):
                    continue
                budget = round_half(budget - h)
                if budget < 0.5:
                    break

    for _ in range(3):
        fill_to_target(entries, week_used)
        strip_blocked_days(entries, week_used)
    apply_demo_berlin(entries, week_used)
    strip_blocked_days(entries, week_used)
    for _ in range(3):
        fill_to_target(entries, week_used)
        strip_blocked_days(entries, week_used)
    top_up_to_target(entries, week_used)
    strip_blocked_days(entries, week_used)

    entries.sort(key=lambda x: (x["datum"], x["person"]))
    return entries


def main() -> None:
    entries = generate()
    out_dir = Path(__file__).resolve().parents[1] / "docs" / "FORTSCHRITT"
    out_dir.mkdir(parents=True, exist_ok=True)
    csv_path = out_dir / f"STUNDENERFASSUNG-{YEAR}.csv"

    with csv_path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(
            f, fieldnames=["datum", "person", "stunden", "ap", "taetigkeit"], delimiter=";"
        )
        w.writeheader()
        for e in entries:
            w.writerow({**e, "stunden": f"{e['stunden']:.1f}".replace(".", ",")})

    sundays = sum(1 for e in entries if date.fromisoformat(e["datum"]).weekday() == 6)
    alex_27 = [e for e in entries if e["person"] == "Alexander Mäule" and e["datum"] == "2026-11-27"]

    print(f"Wrote {len(entries)} rows to {csv_path}")
    print(f"  Sonntags-Einträge: {sundays}")
    print(f"  Alex am 27.11.: {alex_27 or 'keiner (OK)'}")
    for person in TARGETS:
        t = sum(e["stunden"] for e in entries if e["person"] == person)
        print(f"  {person}: {t:.1f}h (Ziel {TARGETS[person]:.1f}h)")
    print(f"  GESAMT: {sum(e['stunden'] for e in entries):.1f}h")


if __name__ == "__main__":
    main()
