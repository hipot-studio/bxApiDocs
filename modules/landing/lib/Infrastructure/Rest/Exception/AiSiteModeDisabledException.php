<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

use Bitrix\Landing\Integration\AiAssistant;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The method belongs to the AI sites generation that is not selected on the portal.
 *
 * The registry code keeps the prefix of the module, so the exact marker every other layer answers
 * with travels as a field of its own.
 */
class AiSiteModeDisabledException extends LandingRestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	public function getRegistryCode(): string
	{
		return 'LANDING_AI_SITES_MODE_DISABLED';
	}

	public function output($responseLanguage = null): array
	{
		$out = parent::output($responseLanguage);

		$out['reason'] = AiAssistant\Exception\AiSiteModeDisabledException::ERROR_CODE;

		return $out;
	}
}
