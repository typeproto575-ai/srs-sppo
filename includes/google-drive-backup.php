<?php

declare(strict_types=1);

function srs_google_drive_access_token(
    string $clientId,
    string $clientSecret,
    string $refreshToken
): string {
    $response = srs_google_drive_request(
        'POST',
        'https://oauth2.googleapis.com/token',
        null,
        http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]),
        'application/x-www-form-urlencoded'
    );

    try {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Google OAuth returned an invalid response.', 0, $exception);
    }

    if (!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
        throw new RuntimeException('Google OAuth did not return an access token.');
    }

    return $data['access_token'];
}

/**
 * @return list<array{id: string, name: string, mimeType?: string}>
 */
function srs_google_drive_list_files(string $accessToken, string $query): array
{
    $files = [];
    $pageToken = null;

    do {
        $parameters = [
            'q' => $query . ' and trashed = false',
            'pageSize' => 1000,
            'fields' => 'nextPageToken,files(id,name,mimeType)',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ];
        if ($pageToken !== null) {
            $parameters['pageToken'] = $pageToken;
        }

        $response = srs_google_drive_request(
            'GET',
            'https://www.googleapis.com/drive/v3/files?' . http_build_query($parameters),
            $accessToken
        );
        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Google Drive returned an invalid file-list response.', 0, $exception);
        }
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            throw new RuntimeException('Google Drive returned an incomplete file-list response.');
        }

        foreach ($data['files'] as $file) {
            if (is_array($file) && is_string($file['id'] ?? null) && is_string($file['name'] ?? null)) {
                $files[] = [
                    'id' => $file['id'],
                    'name' => $file['name'],
                    'mimeType' => is_string($file['mimeType'] ?? null) ? $file['mimeType'] : null,
                ];
            }
        }
        $pageToken = is_string($data['nextPageToken'] ?? null) ? $data['nextPageToken'] : null;
    } while ($pageToken !== null);

    return $files;
}

function srs_google_drive_create_folder(string $accessToken, string $name): string
{
    $metadata = json_encode([
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
    ], JSON_THROW_ON_ERROR);
    $response = srs_google_drive_request(
        'POST',
        'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true',
        $accessToken,
        $metadata,
        'application/json; charset=UTF-8'
    );

    try {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Google Drive returned an invalid folder response.', 0, $exception);
    }
    if (!is_array($data) || !is_string($data['id'] ?? null) || $data['id'] === '') {
        throw new RuntimeException('Google Drive did not return the new folder ID.');
    }

    return $data['id'];
}

function srs_google_drive_upload(
    string $accessToken,
    string $folderId,
    string $name,
    string $contents
): void {
    $boundary = 'srs_sppo_' . bin2hex(random_bytes(16));
    $metadata = json_encode([
        'name' => $name,
        'parents' => [$folderId],
    ], JSON_THROW_ON_ERROR);
    $body = "--{$boundary}\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . $metadata . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: application/octet-stream\r\n\r\n"
        . $contents . "\r\n"
        . "--{$boundary}--\r\n";

    $response = srs_google_drive_request(
        'POST',
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id',
        $accessToken,
        $body,
        'multipart/related; boundary=' . $boundary
    );
    try {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Google Drive returned an invalid upload response.', 0, $exception);
    }
    if (!is_array($data) || !is_string($data['id'] ?? null) || $data['id'] === '') {
        throw new RuntimeException('Google Drive did not confirm the uploaded backup.');
    }
}

function srs_google_drive_delete_file(string $accessToken, string $fileId): void
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $fileId)) {
        throw new InvalidArgumentException('Google Drive returned an invalid file ID.');
    }

    srs_google_drive_request(
        'DELETE',
        'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?supportsAllDrives=true',
        $accessToken
    );
}

/**
 * @param list<array{id: string, name: string, mimeType?: string}> $files
 * @return list<array{id: string, name: string, mimeType?: string}>
 */
function srs_monthly_backups_to_delete(array $files): array
{
    $backups = array_values(array_filter(
        $files,
        static fn(array $file): bool => preg_match(
            '/^srs-sppo-monthly-\d{4}-(0[1-9]|1[0-2])\.sql\.gz\.enc$/',
            $file['name']
        ) === 1
    ));
    usort(
        $backups,
        static fn(array $left, array $right): int => strcmp($right['name'], $left['name'])
    );

    return array_slice($backups, 6);
}

function srs_google_drive_request(
    string $method,
    string $url,
    ?string $accessToken,
    ?string $body = null,
    ?string $contentType = null
): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Google Drive backups.');
    }

    $headers = ['Accept: application/json'];
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    if ($contentType !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Google Drive request.');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    if (!curl_setopt_array($curl, $options)) {
        curl_close($curl);
        throw new RuntimeException('Could not configure the Google Drive request.');
    }

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Google Drive request failed: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Google Drive request failed with HTTP status ' . $status . '.');
    }

    return $response;
}
