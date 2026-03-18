ALTER TABLE people
    ADD COLUMN city_id INT UNSIGNED NULL AFTER city,
    ADD KEY idx_people_city_id (city_id);

-- Optional foreign key. Add only if your current schema/data allow it.
-- ALTER TABLE people
--     ADD CONSTRAINT fk_people_city_id
--     FOREIGN KEY (city_id) REFERENCES italy_comuni(id)
--     ON UPDATE CASCADE
--     ON DELETE SET NULL;
