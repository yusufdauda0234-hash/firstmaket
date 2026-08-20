# JSON List of Countries, States, and Cities Worldwide
 ### Comprehensive JSON list encompassing countries, states, and cities globally, including local names.
<br /> 

 #### This dataset consolidates information on 240 territories, comprising independent countries and non-independent regions. It provides names in both English and local languages, encompassing regions, countries, provinces, and cities.
<br /> 

 #### Sources
<br /> 
1- https://wikipeda.com/


<br /> 

### Important Note:
#### If you encounter any issues with city names or notice discrepancies in the number of cities, please bring it to our attention.


### Dataset quality audit
To help identify gaps between the published dataset and authoritative administrative lists, run the audit helper:

```
python tools/audit_dataset.py --write-report
```

The command prints a JSON summary to stdout and stores the most recent results in `reports/latest_audit.json`. The report highlights

- countries that are missing state/region files,
- states that do not yet have an associated city list, and
- orphan city files whose names do not match any known state codes.

Reviewing the generated report makes it easier to spot provinces or cities that still need to be sourced from a standard reference
and updated in the repository.


## JSON Path Structure

### Fetch the list of states/provinces for a country:
```
https://cdn.jsdelivr.net/gh/hassantafreshi/Json-List-of-countries-states-and-cities-in-the-world@main/json/states/XX.json
```



### Obtain the list of cities for a specific state/province in a country:
```
https://cdn.jsdelivr.net/gh/hassantafreshi/Json-List-of-countries-states-and-cities-in-the-world@main/json/cites/XX/YY.json
```

XX: The two-letter country code / country abbreviation ISO-3166-1 ALPHA-2
YY: The two-letter state/pro code

