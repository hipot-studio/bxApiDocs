<?php declare(strict_types=1);

namespace Bitrix\AI\Services;

use Bitrix\AI\Guard\IntranetGuard;
use Bitrix\AI\Guard\AITextEngineGuard;
use Bitrix\AI\Guard\CollaberGuard;

/**
 * Answers whether a user may use Copilot engines and see Copilot UI.
 * Obtain via \Bitrix\AI\Container::init()->getItem(\Bitrix\AI\Services\CopilotAccessCheckerService::class).
 *
 * A null user id means "no user context": user guards are skipped and grant access,
 * it is NOT resolved to the current user. Pass the user id explicitly for user-facing checks.
 * The text engine check ignores the user id: Engine::getByCategory() checks the current session user.
 */
class CopilotAccessCheckerService
{
	public function __construct(
		protected IntranetGuard $intranetGuard,
		protected AITextEngineGuard $aiTextEngineGuard,
		protected CollaberGuard $collaberGuard,
	)
	{
	}

	/**
	 * Returns true if Copilot UI may be shown: the user may access engines and a text engine is available
	 * to the current session user.
	 *
	 * @param int|null $userId User id; 0 is the system user, null skips user checks.
	 * @return bool
	 */
	public function canShowInFrontend(?int $userId = null): bool
	{
		if (!$this->canAccessEngines($userId))
		{
			return false;
		}

		return $this->aiTextEngineGuard->hasAccess($userId);
	}

	/**
	 * Returns true if prompt and role libraries may be shown: intranet users only (collabers are excluded)
	 * and a text engine is available to the current session user.
	 *
	 * @param int|null $userId User id; null skips user checks.
	 * @return bool
	 */
	public function canShowLibrariesInFrontend(?int $userId = null): bool
	{
		if (!$this->intranetGuard->hasAccess($userId))
		{
			return false;
		}

		return $this->aiTextEngineGuard->hasAccess($userId);
	}

	/**
	 * Returns true if the user may call engines: the system user (0), intranet users and collabers.
	 *
	 * @param int|null $userId User id; 0 is the system user, null skips user checks.
	 * @return bool
	 */
	public function canAccessEngines(?int $userId = null): bool
	{
		if ($userId === 0)
		{
			return true;
		}

		return $this->intranetGuard->hasAccess($userId) || $this->collaberGuard->hasAccess($userId);
	}
}
