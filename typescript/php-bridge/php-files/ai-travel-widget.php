<?php
/**
 * AI Travel Widget page
 * public_html/ai-travel-widget.php
 *
 * This file initialises the PHP session and passes the CSRF token to
 * the front-end widget.  It does NOT modify your PHPTravels installation.
 */

declare(strict_types=1);

require_once __DIR__ . '/ai-travel-api/../../lib/bridge-client.php';

rs_bootstrap();
$csrfToken = rs_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>I Love Voyage AI Travel</title>
    <meta name="description" content="Search flights, hotels and car hire with AI from one intelligent travel booking experience.">
    <!-- Adjust your existing CSS links here -->
    <style>
        /* ---- Minimal reset so the widget renders standalone ---- */
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; }
    </style>
</head>
<body>

    <!-- AI Travel Widget mount point -->
    <div id="ai-travel-widget"></div>

    <!-- Pass CSRF token to JS (never exposes API keys) -->
    <script>
        window.__RS_CSRF_TOKEN__ = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    </script>

    <!-- Widget JS — versioned to bust Cloudflare cache on deploy -->
    <script src="/ai-travel-widget.js?v=<?php echo filemtime(__DIR__ . '/ai-travel-widget.js') ?: '1'; ?>" defer></script>

</body>
</html>
