<?php

namespace Advanced_Media_Offloader\Factories;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use AdvancedMediaOffloader\Storage\ProviderRegistry;
use Exception;

/**
 * The 4.x entry point for storage providers. The list now lives in ProviderRegistry.
 */
class CloudProviderFactory
{
    /**
     * Create an instance of the selected cloud provider.
     *
     * @param string $provider_key The key representing the cloud provider.
     * @return S3_Provider An instance of the cloud provider class.
     * @throws Exception If the provider key is unsupported.
     */
    public static function create(string $provider_key): S3_Provider
    {
        return self::registry()->make($provider_key);
    }

    /**
     * Get the list of available cloud providers.
     *
     * @return array The list of cloud providers with keys and names.
     */
    public static function getAvailableProviders(): array
    {
        return self::registry()->all();
    }

    private static function registry(): ProviderRegistry
    {
        return advmo()->app()->get(ProviderRegistry::class);
    }
}
