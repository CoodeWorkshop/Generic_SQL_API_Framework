<?php

final class Principal
{
    public function __construct(
        public readonly ?string $userId,
        public readonly string $username,
        public readonly string $authenticationType,
        public readonly ?string $backendRole,
        public readonly bool $frontendAccess,
        public readonly ?string $frontendRole,
        public readonly bool $enabled,
        public readonly array $permissions = []
    ) {}

    public function roles(): array
    {
        return array_values(array_filter([$this->backendRole, $this->frontendRole]));
    }
}
