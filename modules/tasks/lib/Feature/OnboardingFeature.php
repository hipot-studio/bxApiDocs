<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Option;

final class OnboardingFeature extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return Option::get('tasks', 'tasks_onboarding_feature', 'Y') === 'Y';
	}
}
