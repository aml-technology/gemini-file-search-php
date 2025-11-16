# Gemini File Search (PHP)

A tiny, focused PHP client for Google "Generative Language" (Gemini) File Search APIs.

- Configure API key and model
- Minimal methods for common operations (stores, resumable uploads, import, generate with file search)
- Uses GuzzleHttp under the hood, returns decoded arrays

Official docs: https://ai.google.dev/gemini-api/docs/file-search

## Requirements

- PHP 7.4+ (works on PHP 8.x as well)
- ext-json
- Composer

## Installation

```bash
composer require aml-tech/gemini-file-search
```

## Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use AmlTech\GeminiFileSearch\GenAi;

// Option 1: pass API key explicitly
$client = new GenAi('YOUR_GEMINI_API_KEY');

// Option 2: read from environment
// putenv('GEMINI_API_KEY=YOUR_GEMINI_API_KEY');
// $client = new GenAi();

// Create a file search store
$store = $client->createStore('My Knowledge Base');

// Start a resumable upload
$uploadUrl = $client->startResumableUploadToFiles(filesize('path/to/doc.pdf'), 'application/pdf');

// Upload file bytes (single chunk example; for large files, call multiple times with offsets)
$client->uploadFileBytes($uploadUrl, 'path/to/doc.pdf', 0, true);

// Import the uploaded file into a store
$client->importFileToStore($store['name'], 'files/abc123');

// Generate content using the store
$response = $client->generateContentWithStore(
    'Summarize the uploaded document',
    [$store['name']]
);

print_r($response);
```

## Configuration

- API key is taken from the constructor argument or the `GEMINI_API_KEY` environment variable.
- Model defaults to `gemini-2.5-flash`. Use `$client->setModel('gemini-1.5-pro')` to change.
- Base URL defaults to `https://generativelanguage.googleapis.com`.

## Publishing on Packagist (maintainers)

1. Push this repository to GitHub (public).
2. Create a version tag, e.g.: `git tag v0.1.0 && git push origin v0.1.0`.
3. Submit the package at https://packagist.org/packages/submit using the GitHub URL.
4. (Optional) Enable Packagist/GitHub webhook for auto-updates on new tags.

## License

MIT License. See [LICENSE](LICENSE).
