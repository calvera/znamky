<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ValidationFailedExceptionListener
{
    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/api/graphql')) {
            return;
        }

        $validationFailed = $this->findValidationFailedException($event->getThrowable());
        if (null === $validationFailed) {
            return;
        }

        $errors = [];
        foreach ($validationFailed->getViolations() as $violation) {
            $errors[] = [
                'propertyPath' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        $event->setResponse(new JsonResponse([
            'title' => 'Validation Failed',
            'detail' => 'The given data failed validation.',
            'violations' => $errors,
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function findValidationFailedException(\Throwable $throwable): ?ValidationFailedException
    {
        $current = $throwable;
        while (null !== $current) {
            if ($current instanceof ValidationFailedException) {
                return $current;
            }
            $current = $current->getPrevious();
        }

        return null;
    }
}
