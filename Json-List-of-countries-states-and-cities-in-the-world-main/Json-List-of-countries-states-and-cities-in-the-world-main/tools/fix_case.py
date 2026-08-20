import json
import os

def to_title_case(s):
    # Standard Title Case: capitalize first letter of each word
    return ' '.join(word.capitalize() for word in s.split())

def update_cities_to_title_case(directory):
    for root, dirs, files in os.walk(directory):
        for file in files:
            if file.endswith('.json'):
                path = os.path.join(root, file)
                with open(path, 'r', encoding='utf-8') as f:
                    try:
                        data = json.load(f)
                    except json.JSONDecodeError:
                        print(f"Error decoding {path}")
                        continue

                changed = False
                for key in data:
                    if 'n' in data[key]:
                        old_name = data[key]['n']
                        new_name = to_title_case(old_name)
                        if old_name != new_name:
                            data[key]['n'] = new_name
                            changed = True

                if changed:
                    with open(path, 'w', encoding='utf-8') as f:
                        json.dump(data, f, ensure_ascii=False, indent=2)
                    print(f"Updated {path}")

# Update Qatar
update_cities_to_title_case('json/cites/qa')
# Update Romania
update_cities_to_title_case('json/cites/ro')
