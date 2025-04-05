<?php declare(strict_types=1);

namespace Bitrix\AI\Services;

use Bitrix\AI\Guard\IntranetGuard;
use Bitrix\AI\Guard\AITextEngineGuard;

/**
 * For integration this services use
 * 		\Bitrix\AI\Container::init()->getItem(\Bitrix\AI\Services\CopilotAccessCheckerService::class)
 */
class CopilotAccessCheckerService
{
	public function __construct(
		protected IntranetGuard $intranetGuard,
		protected AITextEngineGuard $aiTextEngineGuard,
	)
	{
	}

	public function canShowInFrontend(?int $userId = null): bool
	{
		if (!$this->intranetGuard->hasAccess($userId))
		{
			return false;
		}

		if (!$this->aiTextEngineGuard->hasAccess($userId))
		{
			return false;
		}

		return true;
	}
}
