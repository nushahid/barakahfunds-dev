/*
  Setup notes:
  - Your provided database dump uses table `people`, not `donors`.
  - This script auto-creates the 4 default donor/person rows used by the new anonymous collection page.
  - Payment method is saved as cash by default.
*/

INSERT INTO people (name, city, notes, created_at, updated_at)
SELECT 'Anonymous Donation', 'System', 'Auto generated default collection donor', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM people WHERE name = 'Anonymous Donation'
);

INSERT INTO people (name, city, notes, created_at, updated_at)
SELECT 'Donation Box Collection', 'System', 'Auto generated default collection donor', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM people WHERE name = 'Donation Box Collection'
);

INSERT INTO people (name, city, notes, created_at, updated_at)
SELECT 'Jumma Collection', 'System', 'Auto generated default collection donor', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM people WHERE name = 'Jumma Collection'
);

INSERT INTO people (name, city, notes, created_at, updated_at)
SELECT 'Misc Collection', 'System', 'Auto generated default collection donor', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM people WHERE name = 'Misc Collection'
);

/* Optional check */
SELECT ID, name
FROM people
WHERE name IN (
    'Anonymous Donation',
    'Donation Box Collection',
    'Jumma Collection',
    'Misc Collection'
)
ORDER BY name;
