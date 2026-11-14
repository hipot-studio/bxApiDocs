<?php

namespace Bitrix\Mobile\Internal\Onboarding\Audience;

use Bitrix\Intranet\Service\UserService;
use Bitrix\Main\Loader;

/**
 * The owner of the portal alone - the employee whose account created it, the first
 * administrator of the platform.
 *
 * While the activation series is being rolled out it is sent to nobody else: the series walks
 * the new user of a young portal through setting it up, and that is the owner's path. Everyone
 * else stays with the schedule they had, and the legacy day map is untouched by this - the
 * audience is asked by the campaign pass only.
 *
 * A portal that cannot answer who its owner is gets nobody: fail-open here would hand the
 * campaign to the whole portal, which is exactly what the limit exists to prevent. The reason
 * is written down once, not once per user.
 */
final class PortalOwnerAudience implements CampaignAudience
{
	/** @var array<int, bool> answered once per user, the pass asks about each of them once */
	private array $answers = [];

	private bool $isUnreadableReported = false;

	public function includes(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->answers[$userId] ??= $this->isPortalOwner($userId);
	}

	private function isPortalOwner(int $userId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			$this->reportUnreadable('the intranet module is not installed');

			return false;
		}

		try
		{
			return (new UserService())->isFirstAdmin($userId);
		}
		catch (\Throwable $exception)
		{
			$this->reportUnreadable($exception->getMessage());

			return false;
		}
	}

	private function reportUnreadable(string $reason): void
	{
		if ($this->isUnreadableReported)
		{
			return;
		}

		$this->isUnreadableReported = true;
		\AddMessage2Log('Onboarding campaign: the owner of the portal is unreadable - ' . $reason, 'mobile');
	}
}
