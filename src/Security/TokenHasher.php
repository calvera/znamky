<?php

declare(strict_types=1);

namespace App\Security;

final class TokenHasher
{
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
