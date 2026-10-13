<?php

namespace Advanced_Media_Offloader\Core;

use AdvancedMediaOffloader\Foundation\Application;

/**
 * The 4.x container API on top of the application. It keeps the 4.x signatures and nothing else public,
 * so code that calls it by name or extends it keeps working. New code uses advmo()->app().
 */
class Container
{
    private ?Application $app = null;

    public function __construct(?Application $app = null)
    {
        $this->app = $app;
    }

    public function register(string $id, $concrete): self
    {
        // 4.x factories receive the container they were registered on.
        if ($concrete instanceof \Closure) {
            $factory = $concrete;
            $concrete = fn () => $factory($this);
        }

        $this->app()->register($id, $concrete);

        return $this;
    }

    public function get(string $id)
    {
        return $this->app()->get($id);
    }

    public function has(string $id): bool
    {
        return $this->app()->has($id);
    }

    // A subclass may have its own constructor that never calls this one.
    private function app(): Application
    {
        return $this->app ??= new Application();
    }
}
