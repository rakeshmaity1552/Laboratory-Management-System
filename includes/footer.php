<?php
/**
 * Shared Footer Layout
 * Laboratory Management System (LMS)
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/LMS';
$appName = defined('APP_NAME') ? APP_NAME : 'Laboratory Management System';
$appVersion = defined('APP_VERSION') ? APP_VERSION : '1.0';
$currentYear = date('Y');
?>
    </main>

    <!-- Global Application Footer -->
    <footer style="background-color: var(--bg-secondary, #ffffff); border-top: 1px solid var(--border, #e2e8f0); padding: 1.5rem; margin-top: auto; text-align: center; color: var(--text-secondary, #64748b); font-size: 0.875rem;">
        <div style="max-width: 1300px; margin: 0 auto; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
            <div>
                &copy; <?= $currentYear ?> <strong><?= htmlspecialchars($appName) ?></strong>. All rights reserved. College Computer Lab.
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <span>Version <?= htmlspecialchars($appVersion) ?></span>
                <span style="color: #cbd5e1;">&bull;</span>
                <span>Timestamps in UTC</span>
            </div>
        </div>
    </footer>

    <!-- Application JavaScript -->
    <script src="<?= $baseUrl ?>/assets/js/app.js"></script>
</body>
</html>


