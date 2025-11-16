<?php


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

    // Upload file bytes to an upload URL; set $finalize true to complete
    public function uploadFileBytes(string $uploadUrl, string $filePath, int $offset = 0, bool $finalize = true): array
    {
        if (!is_file($filePath)) {
            throw new \InvalidArgumentException('File not found: ' . $filePath);
        }
        $numBytes = filesize($filePath);
        $headers = [
            'Content-Length: ' . $numBytes,
            'X-Goog-Upload-Offset: ' . $offset,
            'X-Goog-Upload-Command: ' . ($finalize ? 'upload, finalize' : 'upload'),
        ];
        $body = file_get_contents($filePath);
        $url = $uploadUrl;
        return $this->requestJson('POST', $url, $headers, $body, false);
    }

    // After uploading via /upload/v1beta/files, the response returns a file resource with name; import it to store
    public function importFileToStore(string $storeName, string $fileName): array
    {
        $url = $this->baseUrl . '/v1beta/' . rawurlencode($storeName) . ':importFile?key=' . rawurlencode($this->requireKey());
        return $this->requestJson('POST', $url, [ 'Content-Type: application/json' ], [ 'file_name' => $fileName ]);
    }

    // Long running operation helpers
    public function getOperation(string $operationName): array
    {
        $url = $this->baseUrl . '/v1beta/' . rawurlencode($operationName);
        $headers = [ 'x-goog-api-key: ' . $this->requireKey() ];
        return $this->requestJson('GET', $url, $headers);
    }

    public function waitOperation(string $operationName, int $timeoutSeconds = 300, int $pollIntervalSeconds = 5): array
    {
        $start = time();
        do {
            $op = $this->getOperation($operationName);
            if (!empty($op['done'])) {
                return $op;
            }
            sleep($pollIntervalSeconds);
        } while (time() - $start < $timeoutSeconds);
        throw new \RuntimeException('Operation did not complete within timeout: ' . $operationName);
    }

    // --- Generate Content with File Search ---
    public function generateContentWithStore(string $prompt, array $storeNames, ?string $metadataFilter = null, ?array $generationConfig = null): array
    {
        $url = $this->baseUrl . '/v1beta/models/' . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($this->requireKey());
        $tool = [ 'file_search' => [ 'file_search_store_names' => $storeNames ] ];
        if ($metadataFilter !== null && $metadataFilter !== '') {
            $tool['file_search']['metadata_filter'] = $metadataFilter;
        }

        $payload = [
            'contents' => [[ 'parts' => [[ 'text' => $prompt ]] ]],
            'tools' => [ $tool ],
        ];
        if ($generationConfig) {
            $payload['generationConfig'] = $generationConfig;
        }
        return $this->requestJson('POST', $url, [ 'Content-Type: application/json' ], $payload);
    }

    // --- Helpers ---
    private function requireKey(): string
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Gemini API key is not set. Provide it in constructor, setApiKey(), or GEMINI_API_KEY env.');
        }
        return $this->apiKey;
    }

    private function requestJson(string $method, string $url, array $headers = [], $body = null, bool $jsonEncode = true): array
    {
        $raw = $this->requestRaw($method, $url, $headers, $body, $jsonEncode);
        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode JSON response: ' . json_last_error_msg() . ' Body: ' . $raw);
        }
        return $data ?? [];
    }

    private function requestRaw(string $method, string $url, array $headers = [], $body = null, bool $jsonEncode = true): string
    {
        [$status, $respHeaders, $respBody] = $this->execRequest($method, $url, $headers, $body, $jsonEncode, false);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP ' . $status . ' calling ' . $url . ' Response: ' . $respBody);
        }
        return $respBody;
    }

    private function requestRawWithHeaders(string $method, string $url, array $headers = [], $body = null, bool $jsonEncode = true): array
    {
        return $this->execRequest($method, $url, $headers, $body, $jsonEncode, true);
    }

    private function execRequest(string $method, string $url, array $headers = [], $body = null, bool $jsonEncode = true, bool $needHeaders = false): array
    {
        $options = [
            'headers' => $this->normalizeHeaders($headers),
        ];

        if ($body !== null) {
            if ($jsonEncode) {
                if (is_string($body)) {
                    $options['body'] = $body; // assume already JSON encoded
                } else {
                    // Let Guzzle encode JSON and set content-type if not provided
                    $options['json'] = $body;
                }
            } else {
                // Raw body (e.g., binary uploads)
                $options['body'] = is_string($body) ? $body : (string) $body;
            }
        }

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $respBody = (string) $response->getBody();

        $respHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $respHeaders[strtolower($name)] = array_map('trim', $values);
        }

        if ($needHeaders) {
            return [$status, $respHeaders, $respBody];
        }
        return [$status, [], $respBody];
    }

    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            if (is_int($k)) {
                // format: "Name: value"
                $parts = explode(':', $v, 2);
                if (count($parts) === 2) {
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                    if ($name !== '') {
                        $out[$name] = $value;
                    }
                }
            } else {
                // associative: Name => value
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function findHeader(array $respHeaders, string $headerName): ?string
    {
        $key = strtolower($headerName);
        if (!isset($respHeaders[$key])) {
            return null;
        }
        // Return first value, trimming CRLF
        $v = $respHeaders[$key][0] ?? '';
        return trim($v);
    }
}