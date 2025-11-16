<?php

declare(strict_types=1);

namespace AmlTech\GeminiFileSearch;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Gemini File Search REST client (PHP)
 *
 * Small, modular helper for Google Generative Language (Gemini) File Search APIs.
 * - Supports configuring API key and model
 * - Provides tiny methods for common operations
 * - Uses GuzzleHttp, returns decoded arrays
 *
 * Official API docs:
 * https://ai.google.dev/gemini-api/docs/file-search
 */
class GenAi
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private ClientInterface $http;

    public function __construct(?string $apiKey = null, string $model = 'gemini-2.5-flash', string $baseUrl = 'https://generativelanguage.googleapis.com')
    {
        $this->apiKey = $apiKey ?: (string) getenv('GEMINI_API_KEY') ?: '';
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
        // Initialize a default Guzzle client; http_errors=false so we can surface body in our own exceptions
        $this->http = new Client([
            'http_errors' => false,
            'allow_redirects' => true,
            'timeout' => 60,
        ]);
    }

    // --- Configuration ---
    public function setApiKey(string $apiKey): void { $this->apiKey = $apiKey; }
    public function getApiKey(): string { return $this->apiKey; }

    public function setModel(string $model): void { $this->model = $model; }
    public function getModel(): string { return $this->model; }

    public function setBaseUrl(string $baseUrl): void { $this->baseUrl = rtrim($baseUrl, '/'); }
    public function getBaseUrl(): string { return $this->baseUrl; }

    // --- File Search Stores ---
    public function createStore(string $displayName): array
    {
        $url = $this->baseUrl . '/v1beta/fileSearchStores?key=' . rawurlencode($this->requireKey());
        return $this->requestJson('POST', $url, [ 'Content-Type: application/json' ], [ 'displayName' => $displayName ]);
    }

    public function listStores(): array
    {
        $url = $this->baseUrl . '/v1beta/fileSearchStores?key=' . rawurlencode($this->requireKey());
        return $this->requestJson('GET', $url);
    }

    public function getStore(string $storeName): array
    {
        $url = $this->baseUrl . '/v1beta/' . rawurlencode($storeName) . '?key=' . rawurlencode($this->requireKey());
        return $this->requestJson('GET', $url);
    }

    public function deleteStore(string $storeName): void
    {
        $url = $this->baseUrl . '/v1beta/' . rawurlencode($storeName) . '?key=' . rawurlencode($this->requireKey());
        $this->requestRaw('DELETE', $url);
    }

    // --- Resumable upload directly to a File Search store ---
    // Start resumable upload session, returns upload URL
    public function startResumableUploadToStore(string $storeName, int $numBytes, string $mimeType, ?array $customBody = null): string
    {
        $url = $this->baseUrl . '/upload/v1beta/' . rawurlencode($storeName) . ':uploadToFileSearchStore?key=' . rawurlencode($this->requireKey());

        $headers = [
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $numBytes,
            'X-Goog-Upload-Header-Content-Type: ' . $mimeType,
            'Content-Type: application/json',
        ];

        // We only need headers from the response to grab X-Goog-Upload-URL
        [$status, $respHeaders, $body] = $this->requestRawWithHeaders('POST', $url, $headers, $customBody ?? new \stdClass());

        $uploadUrl = $this->findHeader($respHeaders, 'x-goog-upload-url');
        if (!$uploadUrl) {
            throw new \RuntimeException('Failed to obtain X-Goog-Upload-URL for resumable upload. Status ' . $status . ' Body: ' . $body);
        }
        return $uploadUrl;
    }

    // Generic resumable upload start for /upload/v1beta/files (returns upload URL)
    public function startResumableUploadToFiles(int $numBytes, string $mimeType): string
    {
        $url = $this->baseUrl . '/upload/v1beta/files?key=' . rawurlencode($this->requireKey());
        $headers = [
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $numBytes,
            'X-Goog-Upload-Header-Content-Type: ' . $mimeType,
            'Content-Type: application/json',
        ];
        [$status, $respHeaders, $body] = $this->requestRawWithHeaders('POST', $url, $headers, new \stdClass());
        $uploadUrl = $this->findHeader($respHeaders, 'x-goog-upload-url');
        if (!$uploadUrl) {
            throw new \RuntimeException('Failed to obtain X-Goog-Upload-URL for files upload. Status ' . $status . ' Body: ' . $body);
        }
        return $uploadUrl;
    }

    // Upload chunk(s) to the given resumable upload URL
    // Set $finalize=true on the last chunk to complete the upload
    public function uploadFileBytes(string $uploadUrl, string $filePath, int $offset = 0, bool $finalize = false): array
    {
        $fh = fopen($filePath, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Unable to open file: ' . $filePath);
        }
        if ($offset > 0 && fseek($fh, $offset) !== 0) {
            fclose($fh);
            throw new \RuntimeException('Failed to seek to offset: ' . $offset);
        }

        $chunk = fread($fh, 8 * 1024 * 1024); // 8MB chunks by default
        $bytesRead = $chunk !== false ? strlen($chunk) : 0;
        fclose($fh);

        $headers = [
            'X-Goog-Upload-Command: upload' . ($finalize ? ', finalize' : ''),
            'X-Goog-Upload-Offset: ' . $offset,
            'Content-Type: application/octet-stream',
        ];

        $statusBody = $this->requestRaw('POST', $uploadUrl, $headers, $chunk, false);
        $decoded = json_decode($statusBody, true);
        return is_array($decoded) ? $decoded : [ 'raw' => $statusBody ];
    }

    // Imports a previously uploaded file (by path or resource name) into the file search store
    public function importFileToStore(string $storeName, string $fileName): array
    {
        $url = $this->baseUrl . '/v1beta/' . rawurlencode($storeName) . ':importFile?key=' . rawurlencode($this->requireKey());
        $body = [ 'file' => [ 'name' => $fileName ] ];
        return $this->requestJson('POST', $url, [ 'Content-Type: application/json' ], $body);
    }

    // Operations helper
    public function getOperation(string $operationName): array
    {
        $url = $this->baseUrl . '/v1beta/' . rawurlencode($operationName) . '?key=' . rawurlencode($this->requireKey());
        return $this->requestJson('GET', $url);
    }

    public function waitOperation(string $operationName, int $timeoutSeconds = 120, int $pollIntervalSeconds = 2): array
    {
        $deadline = time() + $timeoutSeconds;
        do {
            $op = $this->getOperation($operationName);
            if (!empty($op['done'])) {
                return $op;
            }
            sleep($pollIntervalSeconds);
        } while (time() < $deadline);
        throw new \RuntimeException('Operation did not complete within timeout.');
    }

    // Generate content with attached File Search stores
    public function generateContentWithStore(string $prompt, array $storeNames, ?string $metadataFilter = null, ?array $generationConfig = null): array
    {
        $url = $this->baseUrl . '/v1beta/models/' . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($this->requireKey());
        $contentParts = [ [ 'text' => $prompt ] ];

        $contents = [ [ 'role' => 'user', 'parts' => $contentParts ] ];
        $tools = [
            [
                'fileSearch' => [
                    'fileSearch' => [
                        'metadataFilters' => $metadataFilter ? [ $metadataFilter ] : []
                    ]
                ]
            ]
        ];
        $toolConfig = [
            'fileSearch' => [
                'addToTopK' => true,
                'enableLocalKnowledge' => true,
                'maxBestPaths' => 10
            ]
        ];

        $body = [
            'contents' => $contents,
            'tools' => $tools,
            'toolConfig' => $toolConfig,
        ];
        if ($generationConfig) {
            $body['generationConfig'] = $generationConfig;
        }

        return $this->requestJson('POST', $url, [ 'Content-Type: application/json' ], $body);
    }

    // --- Low-level HTTP helpers ---
    private function requireKey(): string
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('Missing GEMINI_API_KEY; pass into constructor or set env var.');
        }
        return $this->apiKey;
    }

    private function requestJson(string $method, string $url, array $headers = [], $body = null, bool $jsonEncode = true): array
    {
        $resp = $this->execRequest($method, $url, $headers, $body, $jsonEncode, false);
        [$status, , $respBody] = $resp;
        $decoded = json_decode($respBody, true);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP ' . $status . ' calling ' . $url . ' => ' . $respBody);
        }
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode JSON: ' . json_last_error_msg() . ' Body: ' . $respBody);
        }
        return $decoded ?? [];
    }

    private function requestRaw(string $method, string $url, array $headers = [], $body = null, bool $jsonEncode = true): string
    {
        $resp = $this->execRequest($method, $url, $headers, $body, $jsonEncode, false);
        [$status, , $respBody] = $resp;
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP ' . $status . ' calling ' . $url . ' => ' . $respBody);
        }
        return $respBody;
    }

    private function requestRawWithHeaders(string $method, string $url, array $headers = [], $body = null, bool $jsonEncode = true): array
    {
        return $this->execRequest($method, $url, $headers, $body, $jsonEncode, true);
    }

    private function execRequest(string $method, string $url, array $headers = [], $body = null, bool $jsonEncode = true, bool $needHeaders = false): array
    {
        $headers = $this->normalizeHeaders($headers);
        $headers['Authorization'] = 'Bearer ' . $this->requireKey();

        $options = [
            'headers' => $headers,
            'body' => null,
            'json' => null,
        ];
        if ($body !== null) {
            if ($jsonEncode) {
                $options['json'] = $body;
            } else {
                $options['body'] = $body;
            }
        }

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $respHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $respHeaders[strtolower($name)] = implode(', ', $values);
        }
        $respBody = (string) $response->getBody();

        if ($needHeaders) {
            return [ $status, $respHeaders, $respBody ];
        }
        return [ $status, [], $respBody ];
    }

    private function normalizeHeaders(array $headers): array
    {
        $assoc = [];
        foreach ($headers as $k => $v) {
            if (is_int($k)) {
                // Numeric keys: split "Header: value"
                $parts = explode(':', $v, 2);
                if (count($parts) === 2) {
                    $assoc[trim($parts[0])] = trim($parts[1]);
                }
            } else {
                $assoc[$k] = $v;
            }
        }
        return $assoc;
    }

    private function findHeader(array $respHeaders, string $headerName): ?string
    {
        $key = strtolower($headerName);
        return $respHeaders[$key] ?? null;
    }
}
