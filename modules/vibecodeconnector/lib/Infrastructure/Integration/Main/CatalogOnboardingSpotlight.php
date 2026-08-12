<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Integration\Main;

use Bitrix\Main\UI\Spotlight;

final class CatalogOnboardingSpotlight
{
	private const SPOTLIGHT_ID = 'vibecode_catalog_onboarding';
	private const LIFETIME = 2592000;

	// Read-only gate check. Does not mark the spotlight as shown.
	public function isAvailableForUser(int $userId): bool
	{
		return $this->create()->isAvailable($userId);
	}

	// Records that the catalog onboarding has been shown to the user.
	public function markShownForUser(int $userId): void
	{
		$this->create()->setViewDate($userId);
	}

	private function create(): Spotlight
	{
		$spotlight = new Spotlight(self::SPOTLIGHT_ID);
		$spotlight->setUserType(Spotlight::USER_TYPE_ALL);
		$spotlight->setLifetime(self::LIFETIME);

		return $spotlight;
	}
}
