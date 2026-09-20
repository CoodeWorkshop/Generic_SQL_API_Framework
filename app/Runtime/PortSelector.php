<?php

final class PortSelector
{
    private $probe;

    public function __construct(?callable $probe = null)
    {
        $this->probe = $probe ?? static function (string $address, int $port): bool {
            $code = 0;
            $message = '';
            $socket = @stream_socket_server(
                "tcp://{$address}:{$port}",
                $code,
                $message,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            if (!is_resource($socket)) return false;
            fclose($socket);
            return true;
        };
    }

    public function firstAvailable(string $address, int $minimum, int $maximum, array $excluded = []): int
    {
        $this->validateRange($address, $minimum, $maximum);
        $excluded = array_fill_keys(array_map('intval', $excluded), true);
        for ($port = $minimum; $port <= $maximum; $port++) {
            if (!isset($excluded[$port]) && ($this->probe)($address, $port)) return $port;
        }
        throw new RuntimeException('No available API port was found in the configured range.');
    }

    public function isAvailable(string $address, int $port): bool
    {
        $this->validateRange($address, $port, $port);
        return ($this->probe)($address, $port);
    }

    private function validateRange(string $address, int $minimum, int $maximum): void
    {
        if ($address !== '127.0.0.1' || $minimum < 1 || $maximum > 65535 || $minimum > $maximum) {
            throw new InvalidArgumentException('Invalid loopback port range.');
        }
    }
}
