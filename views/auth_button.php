<?php // Шаблон для отображения кнопки авторизации или выхода в зависимости от состояния авторизации
if ($isAuthorized): ?>
    <a href="auth.php?action=logout" class="btn">Выйти</a>
<?php else: ?>
    <script
        class="amocrm_oauth"
        charset="utf-8"
        data-client-id="<?= htmlspecialchars($clientId) ?>"
        data-title="Авторизоваться через amoCRM"
        data-compact="false"
        data-color="default"
        data-state="state"
        data-mode="popup"
        src="https://www.amocrm.ru/auth/button.min.js">
    </script>

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