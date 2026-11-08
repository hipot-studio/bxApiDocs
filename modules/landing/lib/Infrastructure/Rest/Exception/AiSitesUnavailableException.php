<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The product gate refused AI sites for this portal.
 *
 * The gate has several outcomes a client may want to tell apart, and the registry code names none
 * of them: it is one code for the whole refusal. So the reason travels as a field of its own rather
 * than inside the message - it is an identifier the client branches on, and the message is a text
 * the client shows a person.
 */
class AiSitesUnavailableException extends LandingRestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	public function __construct(
		private readonly string $reasonCode,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($previous);
	}

	public function getRegistryCode(): string
	{
		return 'LANDING_AI_SITES_UNAVAILABLE';
	}

	public function output($responseLanguage = null): array
	{
		$out = parent::output($responseLanguage);

		$out['reason'] = $this->reasonCode;

		return $out;
	}
}
