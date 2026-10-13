<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Storage;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Integrations\AmazonS3;
use Advanced_Media_Offloader\Integrations\BackblazeB2;
use Advanced_Media_Offloader\Integrations\Cloudflare_R2;
use Advanced_Media_Offloader\Integrations\DigitalOceanSpaces;
use Advanced_Media_Offloader\Integrations\MinIO;
use Advanced_Media_Offloader\Integrations\Wasabi;
use AdvancedMediaOffloader\Foundation\Container;
use Exception;

/**
 * The storage providers a site can pick from. Add-ons add theirs with add() from a service provider.
 */
class ProviderRegistry
{
    private array $providers = [
        'aws_s3'        => [
            'name'  => 'Amazon S3',
            'class' => AmazonS3::class,
        ],
        'cloudflare_r2' => [
            'name'  => 'Cloudflare R2',
            'class' => Cloudflare_R2::class,
        ],
        'backblaze_b2'  => [
            'name'  => 'Backblaze B2',
            'class' => BackblazeB2::class,
        ],
        'digitalocean'  => [
            'name'  => 'DigitalOcean Spaces',
            'class' => DigitalOceanSpaces::class,
        ],
        'wasabi'        => [
            'name'  => 'Wasabi',
            'class' => Wasabi::class,
        ],
        'minio'         => [
            'name'  => 'Any S3‑Compatible Storage (MinIO, OVHcloud, ...)',
            'class' => MinIO::class,
        ],
    ];

    public function __construct(private Container $container)
    {
    }

    public function add(string $key, string $name, string $class): static
    {
        $this->providers[$key] = ['name' => $name, 'class' => $class];

        return $this;
    }

    public function remove(string $key): static
    {
        unset($this->providers[$key]);

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * Provider key => ['name' => ..., 'class' => ...], after the advmo_cloud_providers filter.
     */
    public function all(): array
    {
        /**
         * Filters the list of available cloud providers.
         *
         * @param array $cloud_providers Provider key => ['name' => ..., 'class' => ...].
         */
        return apply_filters('advmo_cloud_providers', $this->providers);
    }

    /**
     * @throws Exception If the key is unknown or its class is missing.
     */
    public function make(string $key): S3_Provider
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; output handlers escape the message.
        $providers = $this->all();

        if (!isset($providers[$key])) {
            throw new Exception("Unsupported cloud provider: {$key}");
        }

        $class = $providers[$key]['class'];

        if (!class_exists($class)) {
            throw new Exception("Cloud provider class does not exist: {$class}");
        }
        // phpcs:enable

        return $this->container->make($class);
    }
}
