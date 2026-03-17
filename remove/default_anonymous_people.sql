INSERT INTO people (name, notes, created_at, updated_at)
SELECT 'Anonymous Donation', 'System default anonymous collection person', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM people WHERE name = 'Anonymous Donation');

INSERT INTO people (name, notes, created_at, updated_at)
SELECT 'Mosque Donation Box', 'System default anonymous collection person', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM people WHERE name = 'Mosque Donation Box');

INSERT INTO people (name, notes, created_at, updated_at)
SELECT 'Jumma Prayer Collection', 'System default anonymous collection person', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM people WHERE name = 'Jumma Prayer Collection');

INSERT INTO people (name, notes, created_at, updated_at)
SELECT 'Miscellaneous Collection', 'System default anonymous collection person', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM people WHERE name = 'Miscellaneous Collection');
