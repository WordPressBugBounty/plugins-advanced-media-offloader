<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Storage;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use AdvancedMediaOffloader\Diagnostics\DiagnosticMessages;
use Throwable;
use UnexpectedValueException;
use WP_Error;

class ConnectionTester
{
    public function __construct(
        private ProviderRegistry $providers,
        private ConnectionStatusStore $statuses,
        private DiagnosticMessages $messages,
    ) {
    }

    public function test(): array|WP_Error
    {
        $providerKey = advmo_get_cloud_provider_key();
        if ($providerKey === '') {
            return new WP_Error(
                'advmo_provider_not_configured',
                __('Please select a cloud provider and save your settings first.', 'advanced-media-offloader'),
                ['status' => 400]
            );
        }

        try {
            $provider = $this->providers->make($providerKey);
            $status = $this->testProvider($provider, $providerKey);
        } catch (Throwable $exception) {
            $message = sprintf(
                /* translators: %s: connection error message. */
                __('Failed to establish a connection: %s', 'advanced-media-offloader'),
                $this->messages->text($exception->getMessage())
            );
            $status = $this->statuses->save($providerKey, false, $message, '', 'advmo_connection_error');
        }

        if ($this->statuses->read() !== $status) {
            return new WP_Error(
                'advmo_connection_status_not_saved',
                __('The connection test finished, but its result could not be saved. Please try again.', 'advanced-media-offloader'),
                ['status' => 500]
            );
        }

        return $status;
    }

    public function testProvider(S3_Provider $provider, string $providerKey): array
    {
        $result = $provider->checkConnection();
        if ($result === true) {
            return $this->statuses->save($providerKey, true);
        }
        if (!is_wp_error($result)) {
            throw new UnexpectedValueException('The cloud provider returned an invalid connection test result.');
        }

        $data = (array) $result->get_error_data();

        return $this->statuses->save(
            $providerKey,
            false,
            $result->get_error_message(),
            is_scalar($data['hint'] ?? null) ? (string) $data['hint'] : '',
            is_scalar($data['code'] ?? null) ? (string) $data['code'] : ''
        );
    }
}
