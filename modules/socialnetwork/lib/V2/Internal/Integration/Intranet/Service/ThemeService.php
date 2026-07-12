<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Intranet\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Integration\Intranet\ThemePicker;

class ThemeService
{
	public function setDefaultProjectTheme(int $groupId, int $userId, string $siteId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('intranet'))
		{
			return $result->addError(new Error('Intranet module is not installed'));
		}

		$defaultThemeId = ThemePicker::getDefaultPortalThemeId();

		if ($defaultThemeId === null)
		{
			return $result->addError(new Error('Default portal theme was not found'));
		}

		$themePicker = ThemePicker::getThemePicker($groupId, $userId, $siteId);
		if ($themePicker === null)
		{
			return $result->addError(new Error('Theme picker is not available'));
		}

		if (!$themePicker->setCurrentThemeId($defaultThemeId))
		{
			return $result->addError(new Error('Unable to set default project theme'));
		}

		return $result;
	}
}
