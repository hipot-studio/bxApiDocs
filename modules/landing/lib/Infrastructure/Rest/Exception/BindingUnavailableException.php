<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The draft the generation was about to start on stopped being free before it could be reserved:
 * the /sites/ai/ chat or a parallel call took it. This is a race for that one draft rather than a
 * limit on how many generations a user runs at a time - the module has no such limit.
 */
class BindingUnavailableException extends LandingRestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_CONFLICT;

	public function getRegistryCode(): string
	{
		return 'LANDING_AI_SITE_BINDING_UNAVAILABLE';
	}
}
