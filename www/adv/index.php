<?php

declare(strict_types=1);

/**
 * Advertising Request Page — /adv/
 *
 * Public form for companies to inquire about advertising
 * on peoples.ru — banners, sponsored content, celebrity mentions, etc.
 */

$pageTitle = 'Размещение рекламы';
$pageDesc = 'Разместите рекламу на peoples.ru — баннеры, спонсорский контент, упоминания знаменитостей. Более 175 000 профилей и широкая аудитория.';

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
    <link rel="canonical" href="https://in.peoples.ru/adv/">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/booking.css" rel="stylesheet">

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="https://in.peoples.ru/adv/">
    <meta property="og:site_name" content="peoples.ru">
    <meta property="og:locale" content="ru_RU">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>

<!-- Hero -->
<section class="adv-hero">
    <div class="container">
        <h1>Размещение рекламы на peoples.ru</h1>
        <p>Более 175 000 профилей знаменитостей, широкая аудитория и разнообразные форматы рекламы. Расскажите о вашем бренде миллионам читателей.</p>
    </div>
</section>

<!-- Form -->
<section class="py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="booking-form">
                    <form class="adv-request-form">

                        <div class="mb-3">
                            <label class="form-label">Тип рекламы</label>
                            <div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="ad_type" value="banner" id="at_banner" checked>
                                    <label class="form-check-label" for="at_banner">Баннерная реклама</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="ad_type" value="content" id="at_content">
                                    <label class="form-check-label" for="at_content">Размещение материалов</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="ad_type" value="sponsorship" id="at_sponsorship">
                                    <label class="form-check-label" for="at_sponsorship">Спонсорство</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="ad_type" value="other" id="at_other">
                                    <label class="form-check-label" for="at_other">Другое</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Компания / бренд</label>
                            <input type="text" name="company_name" class="form-control"
                                   placeholder="Название компании">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Сообщение <span class="text-danger">*</span></label>
                            <textarea name="message" class="form-control" rows="4" required minlength="10"
                                      placeholder="Опишите, какую рекламу вы хотите разместить, целевую аудиторию и пожелания"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Бюджет</label>
                            <input type="text" name="budget" class="form-control"
                                   placeholder="Например: 50-100 тыс. руб.">
                        </div>

                        <hr>

                        <div class="mb-3">
                            <label class="form-label">Контактное лицо <span class="text-danger">*</span></label>
                            <input type="text" name="contact_name" class="form-control form-control-lg" required
                                   placeholder="Фамилия Имя">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Телефон <span class="text-danger">*</span></label>
                            <input type="tel" name="contact_phone" class="form-control form-control-lg" required
                                   placeholder="+7 (___) ___-__-__">
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="contact_email" class="form-control"
                                   placeholder="your@email.com">
                        </div>

                        <!-- Honeypot -->
                        <div class="booking-hp">
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <button type="submit" class="btn btn-brand btn-lg w-100">
                            <i class="bi bi-send me-1"></i>Отправить заявку
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
                    <h5>Рассмотрим заявку</h5>
                    <p>Наш менеджер изучит ваш запрос и подберёт оптимальные варианты размещения.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">2</div>
                    <h5>Предложим варианты</h5>
                    <p>Подготовим коммерческое предложение с форматами, ценами и прогнозами охвата.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">3</div>
                    <h5>Запустим рекламу</h5>
                    <p>После согласования разместим рекламу и предоставим отчёт об эффективности.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="bg-dark text-light py-4 mt-5">
    <div class="container text-center">
        <p class="mb-1"><a href="https://www.peoples.ru" class="text-light text-decoration-none">peoples.ru</a> — Энциклопедия знаменитостей</p>
        <p class="mb-1"><a href="/booking/" class="text-light text-decoration-none opacity-75">Приглашения</a></p>
        <small class="text-muted">&copy; <?= date('Y') ?> peoples.ru</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/booking.js"></script>
</body>
</html>
