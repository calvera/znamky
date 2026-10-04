<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LogoutRequest
{
    public function __construct(
        #[Assert\Length(max: 4096)]
        public ?string $refresh_token = null,
    ) {
    }
}
