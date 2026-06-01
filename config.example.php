<?php
/**
 * CINEMAX - config example
 *
 * Copy this file to config.php, fill in your real values, and place the
 * real config.php one level above the public project folder.
 *
 * Example:
 *   C:\xampp\htdocs\config.php
 *   C:\xampp\htdocs\CINEMAWEBAPP_SUBMIT_20260526\CINEMAWEBAPP_SUBMIT_20260526\api.php
 *
 * Never submit or commit the real config.php because it contains secrets.
 */

// Database
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'cinema');
define('DB_USER', 'root');
define('DB_PASS', '');

// TMDB API
// Used only on the server by tmdb_service.php and tmdb_sync.php.
define('TMDB_API_KEY', 'your_tmdb_api_key_here');
define('TMDB_BEARER_TOKEN', 'your_tmdb_bearer_token_here');

// Manual TMDB sync secret:
//   https://your-site.example/tmdb_sync.php?key=THIS_VALUE
define('CRON_SECRET', 'replace_me_with_a_long_random_string');

// Password reset email
define('MAIL_FROM_ADDRESS', 'no-reply@cinemax.local');
define('MAIL_FROM_NAME', 'CINEMAX Support');

// Local testing only. Keep false for production/demo submission.
define('ENABLE_LOCAL_EMAIL_DEBUG', false);
