<?php

final class Principal
{
    public function __construct(
        public readonly ?string $userId,
        public readonly string $username,
        public readonly string $authenticationType,
        public readonly array $roles,
        public readonly bool $enabled,
        public readonly bool $isAdmin,
        public readonly array $permissions = []
    ) {}
}
