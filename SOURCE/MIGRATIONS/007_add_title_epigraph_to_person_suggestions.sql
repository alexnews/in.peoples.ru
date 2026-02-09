-- 007: Add title, epigraph, and photo columns to user_person_suggestions
-- title = person's rank/occupation ("Звание"), e.g. "Советский кинорежиссёр"
-- epigraph = short description of the biography article
-- person_photo_path = portrait photo (temp storage)
-- photo_path = article photo (temp storage)
ALTER TABLE user_person_suggestions
    ADD COLUMN title VARCHAR(500) DEFAULT NULL AFTER cc2,
    ADD COLUMN epigraph VARCHAR(1000) DEFAULT NULL AFTER title,
    ADD COLUMN person_photo_path VARCHAR(500) DEFAULT NULL AFTER source_url,
    ADD COLUMN photo_path VARCHAR(500) DEFAULT NULL AFTER person_photo_path;
