<?php

class SqlParserException extends RuntimeException
{
    public function __construct(string $message, public readonly int $position = 0)
    {
        parent::__construct($message);
    }
}
