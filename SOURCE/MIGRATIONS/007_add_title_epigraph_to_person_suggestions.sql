-- 007: Add title and epigraph columns to user_person_suggestions
-- Title = article heading ("Заголовок"), Epigraph = short description / who is this person
ALTER TABLE user_person_suggestions
    ADD COLUMN title VARCHAR(500) DEFAULT NULL AFTER cc2,
    ADD COLUMN epigraph VARCHAR(1000) DEFAULT NULL AFTER title;
