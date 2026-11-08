<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

/**
 * The ai module is not available on the portal, so no generation can be started.
 *
 * Answered as unavailable rather than forbidden: nothing about the caller is wrong, the portal is
 * missing a module. A client that gives up on 4xx and repeats 5xx would otherwise write the feature
 * off for good instead of asking again once the module is installed. \CRestServer has no constant
 * for this status line.
 *
 * The only heir that does not declare SkipWriteToLogException: an outage of the portal has to
 * leave a journal record, while a refusal of the contract does not.
 */
class AiModuleUnavailableException extends LandingRestException
{
	protected const STATUS = '503 Service Unavailable';

	public function getRegistryCode(): string
	{
		return 'LANDING_AI_MODULE_UNAVAILABLE';
	}
}
