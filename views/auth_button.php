<?php // Шаблон для отображения кнопки авторизации или выхода в зависимости от состояния авторизации
if ($isAuthorized): ?>
    <form action="auth.php" method="post">
        <input type="hidden" name="action" value="logout">
        <button class="btn">Выйти</button>
    </form>
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
<?php endif; ?>