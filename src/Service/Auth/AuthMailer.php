<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final readonly class AuthMailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from,
    ) {
    }

    public function sendEmailVerification(User $user, string $rawToken): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->from))
            ->to($user->getEmail())
            ->subject('Verify your email')
            ->htmlTemplate('email/verify_email.html.twig')
            ->context([
                'token' => $rawToken,
            ]);

        $this->mailer->send($email);
    }

    public function sendPasswordReset(User $user, string $rawToken): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->from))
            ->to($user->getEmail())
            ->subject('Reset your password')
            ->htmlTemplate('email/reset_password.html.twig')
            ->context([
                'token' => $rawToken,
            ]);

        $this->mailer->send($email);
    }
}
