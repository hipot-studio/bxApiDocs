<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Service;

use Bitrix\Landing\Infrastructure\Rest;
use Bitrix\Landing\Integration\AiAssistant;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\RestException;

/**
 * The single place where an AI site domain failure becomes an answer of the REST contract.
 *
 * A domain exception without a mapping is not this layer's business: `null` tells the caller to
 * rethrow the original, which the framework turns into a plain 500 without details.
 */
final class DomainExceptionMapper
{
	public function map(\Throwable $exception): ?RestException
	{
		return match (true)
		{
			// A generation owned by somebody else answers as a missing one: its existence stays hidden.
			$exception instanceof AiAssistant\Exception\GenerationNotFoundException,
			$exception instanceof AiAssistant\Exception\GenerationAccessDeniedException
				=> new Rest\Exception\GenerationNotFoundException($exception),

			$exception instanceof AiAssistant\Exception\SiteCreateAccessDeniedException
				=> new AccessDeniedException($exception),

			$exception instanceof AiAssistant\Exception\AiSitesDisabledException
				=> new Rest\Exception\AiSitesDisabledException($exception),

			$exception instanceof AiAssistant\Exception\AiSitesUnavailableException
				=> new Rest\Exception\AiSitesUnavailableException($exception->getReasonCode(), $exception),

			$exception instanceof AiAssistant\Exception\AiModuleUnavailableException
				=> new Rest\Exception\AiModuleUnavailableException($exception),

			$exception instanceof AiAssistant\Exception\BindingUnavailableException
				=> new Rest\Exception\BindingUnavailableException($exception),

			$exception instanceof AiAssistant\Exception\ModerationBlockLimitException
				=> new Rest\Exception\ModerationBlockLimitException($exception),

			default => null,
		};
	}
}
