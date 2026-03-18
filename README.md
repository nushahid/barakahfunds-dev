# BarakahFunds Italy Comuni search solution

This package gives you a full database-based city search flow for `add_person.php`.

## What is included

- `sql/001_create_italy_comuni.sql` - master table for Italian comuni
- `sql/002_add_people_city_id.sql` - optional `people.city_id` field
- `sql/003_seed_sample_data.sql` - a few sample rows for quick testing
- `data/italy_comuni_sample.csv` - sample CSV structure for testing the importer
- `import/import_italy_comuni.php` - admin/operator import page for CSV uploads
- `ajax/search_comuni.php` - live AJAX search endpoint
- `assets/city-combobox.css` - dropdown styling
- `assets/city-combobox.js` - frontend searchable dropdown logic
- `docs/add_person_patch_snippet.php` - exact city field replacement block

## Recommended production flow

1. Run `sql/001_create_italy_comuni.sql`
2. Run `sql/002_add_people_city_id.sql` if you want exact comune linkage
3. Import a complete comuni CSV using `import/import_italy_comuni.php`
4. Add `ajax/search_comuni.php` and the two assets into your project
5. Update `add_person.php` using `docs/add_person_patch_snippet.php`

## Why this structure

- `people.city` keeps your old pages compatible
- `people.city_id` gives exact searchable reference
- `italy_comuni` is indexed and much faster than loading a giant JSON file on every page
- AJAX search works better on mobile and desktop

## CSV header format accepted by importer

Preferred headers:

```csv
istat_code,comune_name,province_code,province_name,region_code,region_name,cadastral_code,cap,is_active
```

The importer also accepts several common aliases such as:

- `codice_istat`
- `comune`
- `sigla`
- `provincia`
- `regione`
- `codice_catastale`

## Important note about the full dataset

This package contains a sample CSV so you can test immediately.
For the full national dataset, import an up-to-date comuni CSV from an official or maintained source.

## Suggested placement inside your project

- copy `ajax/search_comuni.php` to `/ajax/search_comuni.php`
- copy `assets/city-combobox.css` to your public CSS folder
- copy `assets/city-combobox.js` to your public JS folder
- copy `import/import_italy_comuni.php` into an admin/import folder

## Search behavior

- starts searching after 2 letters
- matches both normal and normalized names
- works with accents and common alternate spellings better than plain `LIKE`

## Compatibility tip

If you are not ready to use `city_id` yet, you can still deploy this package and save only the `city` text. The frontend already fills that field.
