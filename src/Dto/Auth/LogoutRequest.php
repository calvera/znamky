<?php

declare(strict_types=1);

namespace App\Dto\Auth;

final readonly class LogoutRequest
{
    public function __construct(
        public ?string $refresh_token = null,
    ) {
    }
}
