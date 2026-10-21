<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Diagnostics;

class DiagnosticMessages
{
    private ?array $secrets = null;

    public function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $message = str_replace($this->secrets(), '[redacted]', $value);
        $message = preg_replace('#(https?://)[^\s/@]+:[^\s/@]+@#i', '$1[redacted]@', $message);
        $message = preg_replace('/([?&](?:X-Amz-[^=\s&]+|AWSAccessKeyId|Signature|token|secret|key)=)[^\s&]+/i', '$1[redacted]', $message);
        if (defined('ABSPATH')) {
            $message = str_replace(ABSPATH, '[site]/', $message);
        }

        return sanitize_text_field(substr($message, 0, 2000));
    }

    /** @param string[] $messages */
    public function clean(array $messages): array
    {
        return array_values(array_filter(array_map([$this, 'text'], array_slice($messages, -50)), 'strlen'));
    }

    private function secrets(): array
    {
        if ($this->secrets !== null) {
            return $this->secrets;
        }

        $secrets = [];
        $credentials = get_option('advmo_credentials', []);
        foreach (is_array($credentials) ? $credentials : [] as $fields) {
            foreach (is_array($fields) ? $fields : [] as $field => $value) {
                if (preg_match('/(?:key|secret|token|password)/i', (string) $field) && is_string($value) && $value !== '') {
                    $secrets[] = $value;
                }
            }
        }

        foreach (get_defined_constants() as $name => $value) {
            if (preg_match('/^ADVMO_.*_(?:KEY|SECRET|TOKEN|PASSWORD)$/', $name) && is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }

        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this->secrets = array_values(array_unique($secrets));
    }
}
