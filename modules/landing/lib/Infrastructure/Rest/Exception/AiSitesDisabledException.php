<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * AI sites are switched off for the portal by the release option.
 */
class AiSitesDisabledException extends LandingRestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	public function getRegistryCode(): string
	{
		return 'LANDING_AI_SITES_DISABLED';
	}
}
