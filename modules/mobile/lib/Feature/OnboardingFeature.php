<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\UserTable;

class OnboardingFeature extends FeatureFlag
{
	private int $featureReleaseTimestamp = 1760680800; // 2025-10-17 6:00:00

	public function isEnabled(): bool
	{
		return $this->isMobileOnboardingEnabled() && $this->isNewUser();
	}

	public function isMobileOnboardingEnabled(): bool
	{
		return Option::get('mobile', 'should_show_onboarding', 'Y') === 'Y';
	}

	public function isNewUser(): bool
	{
		$userId = CurrentUser::get()->getId();

		$user = UserTable::getById($userId)->fetchObject();

		if ($user && $user->getDateRegister())
		{
			return $user->getDateRegister()->getTimestamp() >= $this->featureReleaseTimestamp;
		}

		return false;
	}

	public function enable(): void
	{
		Option::set('mobile', 'should_show_onboarding', 'Y');
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => 'should_show_onboarding']);
	}
}

