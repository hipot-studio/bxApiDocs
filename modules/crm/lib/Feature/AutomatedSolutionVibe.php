<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Main\Localization\Loc;

final class AutomatedSolutionVibe extends BaseFeature
{
	public static function isEnabledForAutomatedSolution(array $automatedSolution): bool
	{
		return ($automatedSolution['IS_VIBE_ENABLED'] ?? false) === true;
	}

	public static function normalizeValue(mixed $value): bool
	{
		return in_array(
			$value,
			[true, 1, '1', 'y', 'Y', 'true'],
			true,
		);
	}

	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_AUTOMATED_SOLUTION_VIBE_NAME');
	}

	public function enable(): void
	{
		if ($this->isEnabled())
		{
			return;
		}

		parent::enable();

		IntranetManager::clearLeftMenuCache();
	}

	public function disable(): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		parent::disable();

		IntranetManager::clearLeftMenuCache();
	}
}
