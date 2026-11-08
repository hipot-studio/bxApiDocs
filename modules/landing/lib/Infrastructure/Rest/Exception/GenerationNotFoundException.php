<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * There is no site generation the caller may work with. A generation owned by another user answers
 * the same way as a missing one, so its existence stays hidden.
 */
class GenerationNotFoundException extends LandingRestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_NOT_FOUND;

	public function getRegistryCode(): string
	{
		return 'LANDING_AI_SITE_GENERATION_NOT_FOUND';
	}
}
