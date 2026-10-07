<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $from,
    ) {
    }

    public function sendEmailVerification(User $user, string $rawToken, string $locale = 'en'): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->from))
            ->to($user->getEmail())
            ->subject($this->translator->trans('verify_email.subject', [], 'emails', $locale))
            ->locale($locale)
            ->htmlTemplate('email/verify_email.html.twig')
            ->textTemplate('email/verify_email.txt.twig')
            ->context([
                'token' => $rawToken,
                'locale' => $locale,
            ]);

        $this->mailer->send($email);
    }

    public function sendPasswordReset(User $user, string $rawToken, string $locale = 'en'): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->from))
            ->to($user->getEmail())
            ->subject($this->translator->trans('reset_password.subject', [], 'emails', $locale))
            ->locale($locale)
            ->htmlTemplate('email/reset_password.html.twig')
            ->textTemplate('email/reset_password.txt.twig')
            ->context([
                'token' => $rawToken,
                'locale' => $locale,
            ]);

        $this->mailer->send($email);
    }
}
