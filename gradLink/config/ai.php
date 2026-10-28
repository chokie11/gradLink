<?php

// define('USE_GEMINI', true); // true only if you have a Gemini API key
// define('GEMINI_API_KEY', 'AIzaSyDSePN3tuIsthDGm6NjZoMfZ3QFNCAw2IQ');
// // define('GEMINI_MODEL', 'gemini-2.5-flash');
// define('GEMINI_MODEL', 'gemini-flash-latest');

/**
 * AI configuration for Gemini.
 * Do not hard-code your real API key here.
 */

define('USE_GEMINI', true);

$geminiApiKey = getenv('GEMINI_API_KEY');

if (!$geminiApiKey && file_exists(__DIR__ . '/local_ai_key.php')) {
    $geminiApiKey = require __DIR__ . '/local_ai_key.php';
}

define('GEMINI_API_KEY', $geminiApiKey ?: '');
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash');