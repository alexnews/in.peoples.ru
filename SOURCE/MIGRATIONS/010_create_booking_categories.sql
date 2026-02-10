-- Migration 010: Create booking_categories table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS booking_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(100) NOT NULL,
    description     VARCHAR(500) DEFAULT NULL,
    icon            VARCHAR(50) DEFAULT NULL,
    sort_order      INT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_slug (slug),
    KEY idx_sort_order (sort_order),
    KEY idx_active (is_active)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;

-- Seed data
INSERT INTO booking_categories (name, slug, description, icon, sort_order) VALUES
('Ведущие', 'vedushchie', 'Ведущие и шоумены для мероприятий', 'bi-mic', 1),
('Певцы и музыканты', 'pevtsy-muzykanty', 'Вокалисты, группы, инструменталисты', 'bi-music-note-beamed', 2),
('Блогеры', 'blogery', 'Популярные блогеры и инфлюенсеры', 'bi-camera-video', 3),
('Комики и юмористы', 'komiki', 'Стендап-комики, КВН-щики, юмористы', 'bi-emoji-laughing', 4),
('DJ', 'dj', 'Диджеи для вечеринок и мероприятий', 'bi-disc', 5),
('Актёры', 'aktyory', 'Актёры театра и кино', 'bi-film', 6),
('Спортсмены', 'sportsmeny', 'Известные спортсмены', 'bi-trophy', 7),
('Писатели и поэты', 'pisateli', 'Авторы, писатели, поэты', 'bi-book', 8);
