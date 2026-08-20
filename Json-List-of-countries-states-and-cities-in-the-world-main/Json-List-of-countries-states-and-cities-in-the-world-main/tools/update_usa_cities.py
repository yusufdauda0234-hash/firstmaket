import csv
import json
import os

# Paths
csv_path = r'c:\Users\Kian Co\Downloads\simplemaps_uscities_basicv1.93\uscities.csv'
cities_out_dir = r'c:\files\projects\Json-List-of-countries-states-and-cities-in-the-world\json\cites\us'

# Ensure output directory exists
os.makedirs(cities_out_dir, exist_ok=True)

# Map state_id to cities
state_cities = {}

with open(csv_path, mode='r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        state_id = row['state_id'].lower()
        city_name = row['city']

        if state_id not in state_cities:
            state_cities[state_id] = []

        state_cities[state_id].append(city_name)

# Sort and write to json files
for state_id, cities in state_cities.items():
    # Sort cities alphabetically
    cities.sort()

    # Structure as {"1": {"n": "City1", "l": ""}, "2": {"n": "City2", "l": ""}, ...}
    structured_data = {}
    for i, city in enumerate(cities, 1):
        structured_data[str(i)] = {"n": city, "l": ""}

    file_path = os.path.join(cities_out_dir, f"{state_id}.json")
    with open(file_path, 'w', encoding='utf-8') as out_f:
        json.dump(structured_data, out_f, indent=0, ensure_ascii=False)

print("USA cities update complete.")
