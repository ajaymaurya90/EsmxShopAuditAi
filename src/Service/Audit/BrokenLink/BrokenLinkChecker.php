<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\BrokenLink;

class BrokenLinkChecker
{
    public function check(string $url, int $timeout = 5): array
    {
        // Default result
        $result = [
            'url' => $url,
            'status' => null,
            'error' => null,
        ];

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $result['error'] = 'invalid_url';
            return $result;
        }

        try {
            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true, // HEAD request (faster)
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            curl_exec($ch);

            if (curl_errno($ch)) {
                $result['error'] = curl_error($ch);
            } else {
                $result['status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }

            curl_close($ch);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    public function isBroken(array $checkResult): bool
    {
        // Invalid URL
        if ($checkResult['error'] === 'invalid_url') {
            return true;
        }

        // Curl/network error
        if (!empty($checkResult['error'])) {
            return true;
        }

        // HTTP 4xx or 5xx
        if ($checkResult['status'] !== null && $checkResult['status'] >= 400) {
            return true;
        }

        return false;
    }
}
