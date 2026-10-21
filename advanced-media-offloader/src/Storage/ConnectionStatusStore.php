<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Storage;

class ConnectionStatusStore
{
    public function read(): array
    {
        $status = get_option('advmo_last_connection_status', []);

        return is_array($status) ? $status : [];
    }

    public function save(string $providerKey, bool $success, string $message = '', string $hint = '', string $code = ''): array
    {
        $status = [
            'provider' => $providerKey,
            'success' => $success,
            'message' => $message,
            'hint' => $hint,
            'code' => $code,
            'time' => time(),
        ];

        update_option('advmo_last_connection_status', $status, false);

        return $status;
    }
}
