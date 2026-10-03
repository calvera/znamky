<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Component\Mime\Email;

trait EmailTokenTestTrait
{
    private function extractTokenFromLastEmail(): string
    {
        $email = self::getMailerMessage();
        self::assertNotNull($email);

        if ($email instanceof Email) {
            $body = $email->getHtmlBody() ?: $email->getTextBody() ?: '';
        } else {
            $body = $email->toString();
        }

        self::assertIsString($body);
        self::assertMatchesRegularExpression('/([a-f0-9]{64})/', $body);
        preg_match('/([a-f0-9]{64})/', $body, $matches);

        return $matches[1];
    }
}
