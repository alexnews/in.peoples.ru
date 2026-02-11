<?php

declare(strict_types=1);

/**
 * Info Change Request Page — /info-change/
 *
 * Public form for celebrities, managers, or fans to request corrections
 * to person profiles on peoples.ru.
 */

$pageTitle = 'Изменить информацию о персоне';
$pageDesc = 'Запросите изменение информации о знаменитости на peoples.ru — исправьте биографию, фото, даты и другие данные';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — peoples.ru</title>
    <meta name="description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/booking.css" rel="stylesheet">
</head>
<body>

<!-- Hero -->
<section class="info-change-hero">
    <div class="container">
        <h1>Изменить информацию о персоне</h1>
        <p>Вы — артист, менеджер или представитель? Нашли неточность в биографии? Заполните форму, и мы внесём исправления.</p>
    </div>
</section>

<!-- Form -->
<section class="py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="booking-form">
                    <form class="info-change-form">
                        <input type="hidden" name="person_id" value="">
                        <input type="hidden" name="person_name_manual" value="">

                        <div class="mb-3 position-relative">
                            <label class="form-label">Кого нужно изменить? <span class="text-danger">*</span></label>
                            <input type="text" name="person_search" class="form-control form-control-lg"
                                   placeholder="Начните вводить имя..." autocomplete="off">
                            <div class="list-group person-search-results mt-1" style="display:none; position:absolute; z-index:100; left:0; right:0;"></div>
                            <small class="form-text text-muted">Выберите из списка или введите имя вручную</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Кто вы?</label>
                            <select name="requester_role" class="form-select">
                                <option value="other">— Выберите —</option>
                                <option value="self">Я сам артист / персона</option>
                                <option value="manager">Менеджер / представитель</option>
                                <option value="relative">Родственник</option>
                                <option value="fan">Поклонник</option>
                                <option value="other">Другое</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Что изменить?</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="change_fields[]" value="biography" id="cf_bio">
                                    <label class="form-check-label" for="cf_bio">Биография</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="change_fields[]" value="photo" id="cf_photo">
                                    <label class="form-check-label" for="cf_photo">Фото</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="change_fields[]" value="dates" id="cf_dates">
                                    <label class="form-check-label" for="cf_dates">Даты жизни</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="change_fields[]" value="contacts" id="cf_contacts">
                                    <label class="form-check-label" for="cf_contacts">Контакты</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="change_fields[]" value="other" id="cf_other">
                                    <label class="form-check-label" for="cf_other">Другое</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Опишите изменения <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="4" required minlength="10"
                                      placeholder="Подробно опишите, что нужно изменить и почему"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Ссылка-подтверждение</label>
                            <input type="url" name="evidence_url" class="form-control"
                                   placeholder="https://ru.wikipedia.org/...">
                            <small class="form-text text-muted">Ссылка на источник (Википедия, официальный сайт и т.д.)</small>
                        </div>

                        <hr>

                        <div class="mb-3">
                            <label class="form-label">Ваше имя <span class="text-danger">*</span></label>
                            <input type="text" name="requester_name" class="form-control form-control-lg" required
                                   placeholder="Фамилия Имя">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Телефон <span class="text-danger">*</span></label>
                            <input type="tel" name="requester_phone" class="form-control form-control-lg" required
                                   placeholder="+7 (___) ___-__-__">
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="requester_email" class="form-control"
                                   placeholder="your@email.com">
                        </div>

                        <!-- Honeypot -->
                        <div class="booking-hp">
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <button type="submit" class="btn btn-brand btn-lg w-100">
                            <i class="bi bi-send me-1"></i>Отправить запрос
                        </button>
                        <div class="form-result mt-3" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- What happens next -->
<section class="booking-steps">
    <div class="container">
        <h2 class="text-center mb-4">Что дальше?</h2>
        <div class="row">
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">1</div>
                    <h5>Рассмотрим запрос</h5>
                    <p>Наши редакторы изучат ваш запрос и проверят информацию.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">2</div>
                    <h5>Внесём изменения</h5>
                    <p>Если информация подтвердится, мы обновим страницу персоны.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">3</div>
                    <h5>Уведомим вас</h5>
                    <p>Свяжемся по телефону или email, когда изменения будут внесены.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="bg-dark text-light py-4 mt-5">
    <div class="container text-center">
        <p class="mb-1"><a href="https://www.peoples.ru" class="text-light text-decoration-none">peoples.ru</a> — Энциклопедия знаменитостей</p>
        <p class="mb-1"><a href="/booking/" class="text-light text-decoration-none opacity-75">Букинг</a></p>
        <small class="text-muted">&copy; <?= date('Y') ?> peoples.ru</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/booking.js"></script>
</body>
</html>
