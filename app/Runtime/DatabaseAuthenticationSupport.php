<?php

final class DatabaseAuthenticationSupport
{
    private string $operatingSystem;

    public function __construct(?string $operatingSystem = null)
    {
        $this->operatingSystem = $operatingSystem ?? PHP_OS_FAMILY;
    }

    public function modes(): array
    {
        return $this->operatingSystem === 'Windows' ? ['sql', 'windows'] : ['sql'];
    }

    public function supports(string $mode): bool
    {
        return in_array(strtolower(trim($mode)), $this->modes(), true);
    }

    public function validate(string $mode): void
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'windows' && $this->operatingSystem !== 'Windows') {
            throw new InvalidArgumentException('Windows Authentication is not supported on this platform.');
        }
        if (!$this->supports($mode)) {
            throw new InvalidArgumentException('Unsupported database authentication type.');
        }
    }
}
