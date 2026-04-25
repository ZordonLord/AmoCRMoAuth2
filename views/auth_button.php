<?php // Шаблон для отображения кнопки авторизации или выхода в зависимости от состояния авторизации
if ($isAuthorized): ?>
    <a href="auth.php?action=logout" class="btn mt-3">Выйти</a>

    <form method="GET" action="auth.php" style="margin-bottom: 12px;">
        <input type="hidden" name="action" value="switchUser">
        <label for="active_user_id">Пользователь:</label>
        <select name="user_id" id="active_user_id" class="form-select">
            <option value="">Новый пользователь (новая авторизация)</option>
            <?php foreach ($users as $user): ?>
                <?php
                $uid = (string)($user['id'] ?? '');
                $name = trim((string)($user['name'] ?? ''));
                $email = trim((string)($user['email'] ?? ''));
                $baseDomain = trim((string)($user['base_domain'] ?? ''));
                $label = $name !== '' ? $name : ($email !== '' ? $email : $uid);
                if ($baseDomain !== '') {
                    $label .= " ({$baseDomain})";
                }
                ?>
                <option value="<?= htmlspecialchars($uid) ?>" <?= $activeUserId === $uid ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn mt-3">Переключить</button>
    </form>
<?php else: ?>
    <form method="GET" action="auth.php" style="margin-bottom: 12px;">
        <input type="hidden" name="action" value="switchUser">
        <label for="active_user_id">Пользователь:</label>
        <select name="user_id" id="active_user_id" class="form-select">
            <option value="">Новый пользователь (новая авторизация)</option>
            <?php foreach ($users as $user): ?>
                <?php
                $uid = (string)($user['id'] ?? '');
                $name = trim((string)($user['name'] ?? ''));
                $email = trim((string)($user['email'] ?? ''));
                $baseDomain = trim((string)($user['base_domain'] ?? ''));
                $label = $name !== '' ? $name : ($email !== '' ? $email : $uid);
                if ($baseDomain !== '') {
                    $label .= " ({$baseDomain})";
                }
                ?>
                <option value="<?= htmlspecialchars($uid) ?>" <?= $activeUserId === $uid ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn mt-3">Переключить</button>
    </form>

    <script
        class="amocrm_oauth"
        charset="utf-8"
        data-name="Simple Integration"
        data-description="Simple description"
        data-redirect_uri="https://localhost:8000/auth.php"
        data-secrets_uri="https://localhost:8000/auth.php"
        data-logo="https://example.com/amocrm_logo.png"
        data-scopes="crm,notifications"
        data-title="Авторизация любого пользователя"
        data-compact="false"
        data-class-name="className"
        data-color="default"
        data-state="<?= htmlspecialchars($state) ?>"
        data-error-callback="functionName"
        data-mode="popup"
        src="https://www.amocrm.ru/auth/button.min.js">
    </script>
<?php endif; ?>