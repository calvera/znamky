<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ForgotPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email = '',
        #[Assert\Choice(choices: ['en', 'cs'])]
        public string $locale = 'en',
    ) {
    }
}
