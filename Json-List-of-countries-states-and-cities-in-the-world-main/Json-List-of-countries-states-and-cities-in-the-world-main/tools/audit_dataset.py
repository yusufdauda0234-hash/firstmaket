"""Audit the JSON dataset for missing states or city files.

Usage:
    python tools/audit_dataset.py --write-report

Without --write-report the audit summary is printed to stdout only.
"""
from __future__ import annotations

import argparse
import json
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, List

DATA_ROOT = Path(__file__).resolve().parent.parent / "json"
CITIES_ROOT = DATA_ROOT / "cites"
STATES_ROOT = DATA_ROOT / "states"
REPORTS_ROOT = Path(__file__).resolve().parent.parent / "reports"


@dataclass
class AuditStats:
    countries_total: int = 0
    countries_with_states: int = 0
    countries_missing_states: int = 0
    states_total: int = 0
    states_missing_city_file: int = 0
    city_files_total: int = 0
    city_records_total: int = 0

    def to_dict(self) -> Dict[str, int]:
        return self.__dict__.copy()


@dataclass
class AuditReport:
    stats: AuditStats
    countries_missing_states: Dict[str, str] = field(default_factory=dict)
    states_missing_cities: Dict[str, List[str]] = field(default_factory=dict)
    orphan_city_files: Dict[str, List[str]] = field(default_factory=dict)
    invalid_city_files: List[str] = field(default_factory=list)

    def to_dict(self) -> Dict[str, object]:
        return {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "stats": self.stats.to_dict(),
            "countries_missing_states": self.countries_missing_states,
            "states_missing_cities": self.states_missing_cities,
            "orphan_city_files": self.orphan_city_files,
            "invalid_city_files": self.invalid_city_files,
        }


def load_countries() -> Dict[str, Dict[str, str]]:
    countries_path = DATA_ROOT / "countries.json"
    with countries_path.open(encoding="utf-8") as handle:
        return json.load(handle)


def audit_dataset() -> AuditReport:
    countries = load_countries()
    stats = AuditStats(countries_total=len(countries))
    missing_states: Dict[str, str] = {}
    missing_cities: Dict[str, List[str]] = {}
    invalid_city_files: List[str] = []

    for country_name, country_payload in countries.items():
        country_code = country_payload.get("s2")
        if not country_code:
            missing_states[country_name] = "Missing alpha-2 code"
            continue

        state_file = STATES_ROOT / f"{country_code.lower()}.json"
        if not state_file.exists():
            missing_states[country_name] = "Missing state file"
            continue

        stats.countries_with_states += 1
        try:
            with state_file.open(encoding="utf-8") as handle:
                states = json.load(handle)
        except json.JSONDecodeError:
            missing_states[country_name] = "Invalid state file"
            continue

        stats.states_total += len(states)
        for state_code, _ in states.items():
            city_file = CITIES_ROOT / country_code.lower() / f"{state_code.lower()}.json"
            if not city_file.exists():
                stats.states_missing_city_file += 1
                missing_cities.setdefault(country_name, []).append(state_code)
                continue

            stats.city_files_total += 1
            try:
                with city_file.open(encoding="utf-8") as handle:
                    cities = json.load(handle)
            except json.JSONDecodeError:
                invalid_city_files.append(str(city_file.relative_to(DATA_ROOT.parent)))
                continue

            if isinstance(cities, dict):
                stats.city_records_total += len(cities)
            elif isinstance(cities, list):
                stats.city_records_total += len(cities)
            else:
                invalid_city_files.append(str(city_file.relative_to(DATA_ROOT.parent)))

    stats.countries_missing_states = len(missing_states)

    # Detect orphan city files (city directories without matching state entries)
    orphan_city_files: Dict[str, List[str]] = {}
    if CITIES_ROOT.exists():
        for country_dir in CITIES_ROOT.iterdir():
            if not country_dir.is_dir():
                continue
            states_file = STATES_ROOT / f"{country_dir.name}.json"
            if not states_file.exists():
                orphan_city_files[country_dir.name.upper()] = sorted(
                    path.stem.upper() for path in country_dir.glob("*.json")
                )
                continue

            with states_file.open(encoding="utf-8") as handle:
                try:
                    states = json.load(handle)
                except json.JSONDecodeError:
                    orphan_city_files[country_dir.name.upper()] = sorted(
                        path.stem.upper() for path in country_dir.glob("*.json")
                    )
                    continue
            state_codes = {code.lower() for code in states.keys()}
            for city_file in country_dir.glob("*.json"):
                if city_file.stem.lower() not in state_codes:
                    orphan_city_files.setdefault(country_dir.name.upper(), []).append(
                        city_file.stem.upper()
                    )
            if country_dir.name.upper() in orphan_city_files:
                orphan_city_files[country_dir.name.upper()].sort()

    return AuditReport(
        stats=stats,
        countries_missing_states=missing_states,
        states_missing_cities=missing_cities,
        orphan_city_files=orphan_city_files,
        invalid_city_files=invalid_city_files,
    )


def main() -> None:
    parser = argparse.ArgumentParser(description="Audit the dataset for missing administrative coverage.")
    parser.add_argument(
        "--write-report",
        action="store_true",
        help="Persist the audit result to reports/latest_audit.json",
    )
    args = parser.parse_args()

    report = audit_dataset()

    print(json.dumps(report.to_dict(), indent=2, ensure_ascii=False))

    if args.write_report:
        REPORTS_ROOT.mkdir(parents=True, exist_ok=True)
        report_path = REPORTS_ROOT / "latest_audit.json"
        with report_path.open("w", encoding="utf-8") as handle:
            json.dump(report.to_dict(), handle, indent=2, ensure_ascii=False)
        print(f"\nReport written to {report_path.relative_to(Path.cwd())}")


if __name__ == "__main__":
    main()
