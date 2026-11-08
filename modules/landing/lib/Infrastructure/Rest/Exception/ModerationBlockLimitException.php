<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * Too many prompts in a row were blocked by topic moderation, so starting a generation is
 * temporarily refused.
 */
class ModerationBlockLimitException extends LandingRestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_TO_MANY_REQUESTS;

	public function getRegistryCode(): string
	{
		return 'LANDING_AI_SITE_MODERATION_BLOCK_LIMIT';
	}
}
