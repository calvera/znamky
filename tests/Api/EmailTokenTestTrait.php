<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Component\Mime\Email;

use function Safe\preg_match;

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
        if (1 !== preg_match('/([a-f0-9]{64})/', $body, $matches) || !isset($matches[1])) {
            self::fail('Expected a 64-char hex token in the email body.');
        }

        return $matches[1];
    }
}
