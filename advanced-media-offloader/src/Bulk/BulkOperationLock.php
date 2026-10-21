<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Bulk;

use Closure;
use InvalidArgumentException;
use WP_Error;
use wpdb;

class BulkOperationLock
{
    private array $held = [];

    public function __construct(private wpdb $database, private Closure $connect)
    {
    }

    public function isHeld(string $scope): bool
    {
        return isset($this->held[$this->name($scope)]);
    }

    public function isBusy(string $scope): bool
    {
        return (string) $this->database->get_var(
            $this->database->prepare('SELECT IS_FREE_LOCK(%s)', $this->name($scope))
        ) !== '1';
    }

    public function run(string $scope, callable $operation, int $waitSeconds = 0): mixed
    {
        $name = $this->name($scope);
        if (isset($this->held[$name])) {
            return $this->busy();
        }

        // Separate connections keep long-lived worker locks independent from attachment
        // and control locks, including on MySQL versions that allow only one named lock.
        $connection = ($this->connect)();
        try {
            if ((string) $connection->get_var($connection->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, min(5, $waitSeconds)))) !== '1') {
                return $this->busy();
            }
            $this->held[$name] = true;
            try {
                return $operation();
            } finally {
                unset($this->held[$name]);
                $connection->get_var($connection->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        } finally {
            $connection->close();
        }
    }

    private function name(string $scope): string
    {
        if (!in_array($scope, ['control', 'worker'], true)) {
            throw new InvalidArgumentException('Unknown bulk lock scope.');
        }
        return 'advmo:bulk:' . $scope . ':' . substr(hash('sha256', DB_NAME . ':' . $this->database->prefix), 0, 40);
    }

    private function busy(): WP_Error
    {
        return new WP_Error('advmo_bulk_busy', __('Bulk offload is busy. Please try again.', 'advanced-media-offloader'), ['status' => 409]);
    }
}
