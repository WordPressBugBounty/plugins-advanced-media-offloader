<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Foundation;

use Closure;
use LogicException;

class Application extends Container
{
    /** @var ServiceProvider[] */
    private array $providers = [];

    private bool $booted = false;

    private ?string $version = null;

    public function __construct(private ?string $pluginFile = null)
    {
        parent::__construct();
    }

    public function pluginFile(): string
    {
        return $this->pluginFile ?? throw new LogicException('The application was created without its plugin file.');
    }

    /**
     * The plugin folder, or a path inside it: basePath('assets/js/app.js').
     */
    public function basePath(string $path = ''): string
    {
        return $this->join(dirname($this->pluginFile()), $path);
    }

    /**
     * The plugin folder's URL, or a URL inside it. WordPress resolves it, so a symlinked plugin works too.
     */
    public function baseUrl(string $path = ''): string
    {
        return $this->join(untrailingslashit(plugin_dir_url($this->pluginFile())), $path);
    }

    /**
     * "advanced-media-offloader/advanced-media-offloader.php", as WordPress names the plugin.
     */
    public function basename(): string
    {
        return plugin_basename($this->pluginFile());
    }

    public function version(): string
    {
        return $this->version ??= (string) get_file_data($this->pluginFile(), ['Version' => 'Version'])['Version'];
    }

    public function isDebug(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    public function runningInConsole(): bool
    {
        return defined('WP_CLI') && WP_CLI;
    }

    /**
     * Registers a service provider (a class name or an instance).
     *
     * register('id', $concrete) also works, as it did on the 4.x container: a closure becomes
     * a lazy singleton, anything else is stored as is. A 4.x id rebinds the class it names.
     */
    public function register(ServiceProvider|string $provider, mixed $concrete = null): mixed
    {
        if (func_num_args() > 1) {
            if ($concrete instanceof Closure) {
                $this->singleton($provider, $concrete);
            } else {
                $this->instance($provider, $concrete);
            }

            return $this;
        }

        if (is_string($provider)) {
            $provider = new $provider($this);
        }

        $provider->register();
        $this->providers[] = $provider;

        if ($this->booted) {
            $provider->boot();
        }

        return $provider;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Add-ons register their providers here, after ours and before anything is resolved.
        do_action('advmo_register_providers', $this);

        // A provider may register another one while booting, so the list can grow.
        for ($i = 0; $i < count($this->providers); $i++) {
            $this->providers[$i]->boot();
        }

        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * @return ServiceProvider[]
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    private function join(string $base, string $path): string
    {
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}
