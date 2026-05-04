<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\BrokenLink;

class BrokenLinkExtractor
{
    /**
     * Extract all URLs from a given HTML/text content.
     *
     * @param string|null $content
     * @return string[]
     */
    public function extractLinks(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        // Decode HTML entities (CRITICAL)
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

        $links = [];

        // Extract ONLY href values from <a> tags
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {

                $url = trim($url);

                // Skip empty / anchors / javascript
                if (
                    $url === '' ||
                    str_starts_with($url, '#') ||
                    str_starts_with($url, 'javascript:')
                ) {
                    continue;
                }

                // Optional: allow only valid URLs or relative paths
                if (
                    !filter_var($url, FILTER_VALIDATE_URL) &&
                    !str_starts_with($url, '/')
                ) {
                    continue;
                }

                $links[] = $url;
            }
        }

        return array_values(array_unique($links));
    }
}
