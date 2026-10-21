<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Diagnostics;

use AdvancedMediaOffloader\Foundation\Application;
use AdvancedMediaOffloader\Media\Contracts\AttachmentRepository;
use AdvancedMediaOffloader\Storage\ConnectionStatusStore;
use AdvancedMediaOffloader\Storage\ProviderRegistry;

class StatusReader
{
    public function __construct(
        private Application $app,
        private AttachmentRepository $attachments,
        private ProviderRegistry $providers,
        private DiagnosticMessages $messages,
        private ?ConnectionStatusStore $connectionStatus = null,
    ) {
    }

    public function read(): array
    {
        $settings = get_option('advmo_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $provider = advmo_get_cloud_provider_key();
        $connection = ($this->connectionStatus ?? $this->app->get(ConnectionStatusStore::class))->read();
        $lastConnection = null;
        $retentionPolicy = $settings['retention_policy'] ?? 0;
        $retentionPolicy = is_numeric($retentionPolicy) ? (int) $retentionPolicy : 0;

        if (is_array($connection) && $provider !== '' && ($connection['provider'] ?? '') === $provider) {
            $lastConnection = [
                'success' => ($connection['success'] ?? false) === true,
                'checked_at' => is_numeric($connection['time'] ?? null) ? max(0, (int) $connection['time']) : null,
                'message' => $this->messages->text($connection['message'] ?? ''),
                'hint' => $this->messages->text($connection['hint'] ?? ''),
                'code' => $this->messages->text($connection['code'] ?? ''),
            ];
        }

        return [
            'version' => $this->app->version(),
            'provider' => $provider,
            'provider_available' => $provider !== '' && $this->providers->has($provider),
            'last_connection_test' => $lastConnection,
            'settings' => [
                'auto_offload_uploads' => $this->enabled($settings['auto_offload_uploads'] ?? 1),
                'retention_policy' => in_array($retentionPolicy, [0, 1, 2], true) ? $retentionPolicy : 0,
                'mirror_delete' => $this->enabled($settings['mirror_delete'] ?? 0),
                'object_versioning' => $this->enabled($settings['object_versioning'] ?? 0),
            ],
            'counts' => $this->attachments->counts(),
        ];
    }

    private function enabled(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}
