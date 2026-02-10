<?php
/**
 * Suggest a new person — requires auth.
 *
 * Structured form that collects person data (names, dates, gender, etc.)
 * and a biography. Inserts into `user_person_suggestions` table (separate
 * from user_submissions).
 *
 * Flow: User submits → Moderator checks content quality → Admin checks
 * for duplicates in `persons` and pushes to the real table.
 */

declare(strict_types=1);

$pageTitle = 'Предложить персону';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/countries.php';

$db = getDb();
$userId = (int) $currentUser['id'];

$success = false;
$errors = [];
$values = [
    'SurNameRus'  => '',
    'NameRus'     => '',
    'SurNameEngl' => '',
    'NameEngl'    => '',
    'DateIn'      => '',
    'DateOut'     => '',
    'gender'      => '',
    'TownIn'      => '',
    'cc2born'     => '',
    'cc2dead'     => '',
    'cc2'         => '',
    'title'       => '',
    'epigraph'    => '',
    'description' => '',
    'source_url'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Ошибка безопасности. Попробуйте ещё раз.';
    } else {
        foreach (array_keys($values) as $key) {
            $values[$key] = trim($_POST[$key] ?? '');
        }

        // Validation
        if (mb_strlen($values['SurNameRus'], 'UTF-8') < 2) {
            $errors['SurNameRus'] = 'Укажите фамилию на русском (минимум 2 символа)';
        }
        if (mb_strlen($values['NameRus'], 'UTF-8') < 2) {
            $errors['NameRus'] = 'Укажите имя на русском (минимум 2 символа)';
        }
        if (mb_strlen($values['title'], 'UTF-8') < 3) {
            $errors['title'] = 'Укажите звание/род деятельности (минимум 3 символа)';
        }
        if (mb_strlen($values['description'], 'UTF-8') < 50) {
            $errors['description'] = 'Опишите биографию подробнее (минимум 50 символов)';
        }
        if ($values['gender'] !== '' && !in_array($values['gender'], ['m', 'f'], true)) {
            $errors['gender'] = 'Некорректное значение';
        }
        if ($values['DateIn'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['DateIn'])) {
            $errors['DateIn'] = 'Формат: ГГГГ-ММ-ДД';
        }
        if ($values['DateOut'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['DateOut'])) {
            $errors['DateOut'] = 'Формат: ГГГГ-ММ-ДД';
        }
        $validCountryCodes = array_keys(getCountries());
        if ($values['cc2born'] !== '' && !in_array(strtoupper($values['cc2born']), $validCountryCodes, true)) {
            $errors['cc2born'] = 'Выберите страну из списка';
        }
        if ($values['cc2dead'] !== '' && !in_array(strtoupper($values['cc2dead']), $validCountryCodes, true)) {
            $errors['cc2dead'] = 'Выберите страну из списка';
        }
        if ($values['cc2'] !== '' && !in_array(strtoupper($values['cc2']), $validCountryCodes, true)) {
            $errors['cc2'] = 'Выберите страну из списка';
        }

        // Handle photo uploads
        $personPhotoPath = null;
        $articlePhotoPath = null;

        if (empty($errors) && !empty($_FILES['person_photo']['name'])) {
            try {
                $info = processUpload($_FILES['person_photo'], $userId);
                $personPhotoPath = $info['file_path'];
            } catch (\InvalidArgumentException $e) {
                $errors['person_photo'] = $e->getMessage();
            } catch (\RuntimeException $e) {
                $errors['person_photo'] = 'Ошибка загрузки фото: ' . $e->getMessage();
            }
        }

        if (empty($errors) && !empty($_FILES['article_photo']['name'])) {
            try {
                $info = processUpload($_FILES['article_photo'], $userId);
                $articlePhotoPath = $info['file_path'];
            } catch (\InvalidArgumentException $e) {
                $errors['article_photo'] = $e->getMessage();
            } catch (\RuntimeException $e) {
                $errors['article_photo'] = 'Ошибка загрузки фото: ' . $e->getMessage();
            }
        }

        if (empty($errors)) {
            $stmt = $db->prepare(
                'INSERT INTO user_person_suggestions
                    (user_id, NameRus, SurNameRus, NameEngl, SurNameEngl,
                     DateIn, DateOut, gender, TownIn,
                     cc2born, cc2dead, cc2,
                     title, epigraph, biography, source_url,
                     person_photo_path, photo_path,
                     status, created_at, updated_at)
                 VALUES
                    (:uid, :nameRus, :surNameRus, :nameEngl, :surNameEngl,
                     :dateIn, :dateOut, :gender, :townIn,
                     :cc2born, :cc2dead, :cc2,
                     :title, :epigraph, :biography, :source_url,
                     :personPhoto, :articlePhoto,
                     \'pending\', NOW(), NOW())'
            );
            $stmt->execute([
                ':uid'          => $userId,
                ':nameRus'      => toDb($values['NameRus']),
                ':surNameRus'   => toDb($values['SurNameRus']),
                ':nameEngl'     => $values['NameEngl'] !== '' ? toDb($values['NameEngl']) : null,
                ':surNameEngl'  => $values['SurNameEngl'] !== '' ? toDb($values['SurNameEngl']) : null,
                ':dateIn'       => $values['DateIn'] !== '' ? $values['DateIn'] : null,
                ':dateOut'      => $values['DateOut'] !== '' ? $values['DateOut'] : null,
                ':gender'       => $values['gender'] !== '' ? $values['gender'] : null,
                ':townIn'       => $values['TownIn'] !== '' ? toDb($values['TownIn']) : null,
                ':cc2born'      => $values['cc2born'] !== '' ? strtoupper($values['cc2born']) : null,
                ':cc2dead'      => $values['cc2dead'] !== '' ? strtoupper($values['cc2dead']) : null,
                ':cc2'          => $values['cc2'] !== '' ? strtoupper($values['cc2']) : null,
                ':title'        => toDb($values['title']),
                ':epigraph'     => $values['epigraph'] !== '' ? toDb($values['epigraph']) : null,
                ':biography'    => toDb($values['description']),
                ':source_url'   => $values['source_url'] !== '' ? toDb($values['source_url']) : null,
                ':personPhoto'  => $personPhotoPath,
                ':articlePhoto' => $articlePhotoPath,
            ]);

            $success = true;
        }
    }
}
?>

<h4 class="mb-4">
    <i class="bi bi-person-plus me-2"></i>Предложить новую персону
</h4>

<div class="row">
    <div class="col-lg-8">
        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-1"></i>
            Ваше предложение отправлено на рассмотрение модератору. Спасибо!
        </div>
        <a href="/user/" class="btn btn-outline-secondary">На главную</a>
        <a href="/user/suggest-person.php" class="btn btn-outline-primary ms-2">Предложить ещё</a>
        <?php else: ?>

        <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-danger py-2">
            <?= htmlspecialchars($errors['general'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <p class="text-muted mb-4">Если вы не нашли нужного человека в базе, предложите его добавить. Модератор рассмотрит заявку и создаст страницу персоны.</p>

        <form method="POST" action="/user/suggest-person.php" enctype="multipart/form-data" novalidate>
            <?= csrfField() ?>

            <!-- Names (Russian) -->
            <div class="card suggest-person-section mb-3">
                <div class="card-header"><i class="bi bi-person me-1"></i>Имя на русском <span class="text-danger">*</span></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="SurNameRus" class="form-label">Фамилия <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($errors['SurNameRus']) ? 'is-invalid' : '' ?>"
                                   id="SurNameRus" name="SurNameRus"
                                   value="<?= htmlspecialchars($values['SurNameRus'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="Иванов" required>
                            <?php if (isset($errors['SurNameRus'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['SurNameRus'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="NameRus" class="form-label">Имя <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($errors['NameRus']) ? 'is-invalid' : '' ?>"
                                   id="NameRus" name="NameRus"
                                   value="<?= htmlspecialchars($values['NameRus'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="Иван" required>
                            <?php if (isset($errors['NameRus'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['NameRus'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Names (English) -->
            <div class="card suggest-person-section mb-3">
                <div class="card-header"><i class="bi bi-translate me-1"></i>Имя на английском</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="SurNameEngl" class="form-label">Фамилия (англ.)</label>
                            <input type="text" class="form-control" id="SurNameEngl" name="SurNameEngl"
                                   value="<?= htmlspecialchars($values['SurNameEngl'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="Ivanov">
                        </div>
                        <div class="col-md-6">
                            <label for="NameEngl" class="form-label">Имя (англ.)</label>
                            <input type="text" class="form-control" id="NameEngl" name="NameEngl"
                                   value="<?= htmlspecialchars($values['NameEngl'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="Ivan">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gender -->
            <div class="card suggest-person-section mb-3">
                <div class="card-header"><i class="bi bi-person-badge me-1"></i>Пол</div>
                <div class="card-body">
                    <div class="gender-btn-group">
                        <input type="radio" class="btn-check" name="gender" id="gender-m" value="m"
                               <?= $values['gender'] === 'm' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary btn-sm" for="gender-m">Мужской</label>
                        <input type="radio" class="btn-check" name="gender" id="gender-f" value="f"
                               <?= $values['gender'] === 'f' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary btn-sm" for="gender-f">Женский</label>
                    </div>
                </div>
            </div>

            <!-- Birth -->
            <div class="card suggest-person-section mb-3">
                <div class="card-header"><i class="bi bi-calendar-event me-1"></i>Рождение</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="DateIn" class="form-label">Дата (ДД.ММ.ГГГГ)</label>
                            <input type="date" class="form-control <?= isset($errors['DateIn']) ? 'is-invalid' : '' ?>"
                                   id="DateIn" name="DateIn"
                                   value="<?= htmlspecialchars($values['DateIn'], ENT_QUOTES, 'UTF-8') ?>">
                            <?php if (isset($errors['DateIn'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['DateIn'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label for="TownIn" class="form-label">Город</label>
                            <input type="text" class="form-control" id="TownIn" name="TownIn"
                                   value="<?= htmlspecialchars($values['TownIn'], ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="Москва">
                        </div>
                        <div class="col-md-4">
                            <label for="cc2born" class="form-label">Страна</label>
                            <?= countrySelect('cc2born', 'cc2born', $values['cc2born'], '', isset($errors['cc2born'])) ?>
                            <?php if (isset($errors['cc2born'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['cc2born'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Death -->
            <div class="card suggest-person-section mb-3">
                <div class="card-header"><i class="bi bi-calendar-x me-1"></i>Смерть</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="DateOut" class="form-label">Дата (ДД.ММ.ГГГГ)</label>
                            <input type="date" class="form-control <?= isset($errors['DateOut']) ? 'is-invalid' : '' ?>"
                                   id="DateOut" name="DateOut"
                                   value="<?= htmlspecialchars($values['DateOut'], ENT_QUOTES, 'UTF-8') ?>">
                            <?php if (isset($errors['DateOut'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['DateOut'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4"></div>
                        <div class="col-md-4">
                            <label for="cc2dead" class="form-label">Страна</label>
                            <?= countrySelect('cc2dead', 'cc2dead', $values['cc2dead'], '', isset($errors['cc2dead'])) ?>
                            <?php if (isset($errors['cc2dead'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['cc2dead'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Residence / Citizenship -->
            <div class="card suggest-person-section mb-3">
                <div class="card-header"><i class="bi bi-globe me-1"></i>Гражданство / страна проживания</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="cc2" class="form-label">Страна</label>
                            <?= countrySelect('cc2', 'cc2', $values['cc2'], '', isset($errors['cc2'])) ?>
                            <?php if (isset($errors['cc2'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['cc2'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Person photo -->
            <div class="card suggest-person-section mb-3">
                <div class="card-header"><i class="bi bi-camera me-1"></i>Фото персоны</div>
                <div class="card-body">
                    <div class="mb-2">
                        <label for="person_photo" class="form-label">Портрет / главное фото</label>
                        <input type="file" class="form-control <?= isset($errors['person_photo']) ? 'is-invalid' : '' ?>"
                               id="person_photo" name="person_photo" accept="image/jpeg,image/png,image/webp">
                        <?php if (isset($errors['person_photo'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errors['person_photo'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <div class="form-text">JPG, PNG или WebP. Максимум 10 МБ.</div>
                    </div>
                </div>
            </div>

            <!-- Zvanie (person's rank/occupation) -->
            <div class="mb-3">
                <label for="title" class="form-label">Звание / род деятельности <span class="text-danger">*</span></label>
                <input type="text" class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                       id="title" name="title"
                       value="<?= htmlspecialchars($values['title'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Советский кинорежиссёр, сценарист" required>
                <?php if (isset($errors['title'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="form-text">Кто этот человек? Например: «Актёр, режиссёр», «Учёный-физик»</div>
            </div>

            <!-- Epigraph (article description) -->
            <div class="mb-3">
                <label for="epigraph" class="form-label">Эпиграф / краткое описание статьи</label>
                <textarea class="form-control" id="epigraph" name="epigraph" rows="2"
                          placeholder="Краткое описание биографической статьи (необязательно)"><?= htmlspecialchars($values['epigraph'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="form-text">Краткая аннотация к статье-биографии</div>
            </div>

            <!-- Biography -->
            <div class="mb-3">
                <label for="description" class="form-label">Содержание <span class="text-danger">*</span></label>
                <textarea class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                          id="description" name="description" rows="6"
                          placeholder="Напишите биографию персоны (минимум 50 символов)..."><?= htmlspecialchars($values['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <?php if (isset($errors['description'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['description'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="form-text">Минимум 50 символов. Напишите биографию персоны.</div>
            </div>

            <!-- Article photo -->
            <div class="mb-3">
                <label for="article_photo" class="form-label">Фото для статьи</label>
                <input type="file" class="form-control <?= isset($errors['article_photo']) ? 'is-invalid' : '' ?>"
                       id="article_photo" name="article_photo" accept="image/jpeg,image/png,image/webp">
                <?php if (isset($errors['article_photo'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['article_photo'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="form-text">Фото к статье-биографии. JPG, PNG или WebP. Максимум 10 МБ.</div>
            </div>

            <!-- Source URL -->
            <div class="mb-3">
                <label for="source_url" class="form-label">Ссылка на источник (Википедия и т.п.)</label>
                <input type="url" class="form-control" id="source_url" name="source_url"
                       value="<?= htmlspecialchars($values['source_url'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="https://ru.wikipedia.org/wiki/...">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send me-1"></i>Отправить на рассмотрение
            </button>
            <a href="/user/" class="btn btn-outline-secondary ms-2">Отмена</a>
        </form>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-info-circle me-1"></i>Подсказки</h6>
                <ul class="small text-muted mb-0" style="padding-left:1.1rem">
                    <li>Убедитесь, что персоны нет в базе (попробуйте разные варианты написания)</li>
                    <li>Укажите фамилию и имя на русском — обязательно</li>
                    <li>Английское написание поможет при поиске</li>
                    <li>Выберите страну из списка (бывший СССР — вверху)</li>
                    <li>Загрузите портрет персоны (если есть)</li>
                    <li>Звание — кто это: «Актёр, режиссёр», «Учёный-физик»</li>
                    <li>Эпиграф — краткое описание статьи</li>
                    <li>Напишите биографию минимум 50 символов</li>
                    <li>По возможности укажите ссылку на Википедию</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
