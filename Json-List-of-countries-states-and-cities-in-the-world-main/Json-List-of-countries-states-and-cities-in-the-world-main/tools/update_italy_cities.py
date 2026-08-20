import json
import os
import unicodedata
import urllib.request

SOURCE_URL = "https://raw.githubusercontent.com/DmitryMyadzelets/italia/main/json/italy.json"

CITIES_OUT_DIR = r'c:\files\projects\Json-List-of-countries-states-and-cities-in-the-world\json\cites\it'
STATES_OUT_FILE = r'c:\files\projects\Json-List-of-countries-states-and-cities-in-the-world\json\states\it.json'

os.makedirs(CITIES_OUT_DIR, exist_ok=True)

# Mapping: source region name -> our state code
REGION_TO_CODE = {
    "Abruzzo":                           "ABR",
    "Basilicata":                        "BAS",
    "Calabria":                          "CAL",
    "Campania":                          "CAM",
    "Emilia-Romagna":                    "EMI",
    "Friuli-Venezia Giulia":             "FRI",
    "Lazio":                             "LAZ",
    "Liguria":                           "LIG",
    "Lombardia":                         "LOM",
    "Marche":                            "MAR",
    "Molise":                            "MOL",
    "Piemonte":                          "PIE",
    "Puglia":                            "PUG",
    "Sardegna":                          "SAR",
    "Sicilia":                           "SIC",
    "Toscana":                           "TOS",
    "Trentino-Alto Adige/Südtirol":      "TRE",
    "Umbria":                            "UMB",
    "Valle d'Aosta/Vallée d'Aoste":      "VDA",
    "Veneto":                            "VEN",
}

# English region name for the "n" field in states.json
REGION_EN = {
    "ABR": "Abruzzo",
    "BAS": "Basilicata",
    "CAL": "Calabria",
    "CAM": "Campania",
    "EMI": "Emilia-Romagna",
    "FRI": "Friuli-Venezia Giulia",
    "LAZ": "Lazio",
    "LIG": "Liguria",
    "LOM": "Lombardy",
    "MAR": "Marche",
    "MOL": "Molise",
    "PIE": "Piedmont",
    "PUG": "Apulia",
    "SAR": "Sardinia",
    "SIC": "Sicily",
    "TOS": "Tuscany",
    "TRE": "Trentino-Alto Adige",
    "UMB": "Umbria",
    "VDA": "Aosta Valley",
    "VEN": "Veneto",
}

def remove_accents(text):
    return ''.join(
        c for c in unicodedata.normalize('NFD', text)
        if unicodedata.category(c) != 'Mn'
    )


def fetch_json(url):
    print(f"Downloading {url} ...")
    with urllib.request.urlopen(url) as resp:
        return json.loads(resp.read().decode('utf-8'))


def update_italy():
    source = fetch_json(SOURCE_URL)

    # ------------------------------------------------------------------ states
    # Collect local names from source
    local_names = {}
    for region in source:
        code = REGION_TO_CODE.get(region["name"])
        if code:
            local_names[code] = region["name"]
        else:
            print(f"  Warning: unmapped region '{region['name']}'")

    states_out = {}
    for code in sorted(REGION_EN.keys()):
        local = local_names.get(code, REGION_EN[code])
        states_out[code] = {"n": REGION_EN[code], "l": local, "s": code}

    with open(STATES_OUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(states_out, f, ensure_ascii=False, separators=(',', ':'))
    print(f"Wrote {len(states_out)} regions to {STATES_OUT_FILE}")

    # ------------------------------------------------------------------ cities
    # Collect all communes per region (flatten provinces)
    grouped = {}  # code -> list of city names

    for region in source:
        code = REGION_TO_CODE.get(region["name"])
        if not code:
            continue
        cities = []
        for province in region.get("provinces", []):
            for comune in province.get("comunes", []):
                cities.append(comune["name"])
        grouped[code] = sorted(set(cities))

    print(f"Found cities in {len(grouped)} regions.")

    for code, cities in grouped.items():
        structured = {str(i): {"n": remove_accents(name), "l": name}
                      for i, name in enumerate(cities, 1)}
        out_path = os.path.join(CITIES_OUT_DIR, f"{code.lower()}.json")
        with open(out_path, 'w', encoding='utf-8') as f:
            json.dump(structured, f, ensure_ascii=False, separators=(',', ':'))
        print(f"  {out_path}: {len(cities)} cities")

    print("Italy update complete.")


if __name__ == "__main__":
    update_italy()
