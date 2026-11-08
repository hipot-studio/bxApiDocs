<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubNavigationProvider;
use Bitrix\Crm\V2\Internal\Service\Settings\Presentation\SettingsPresentationProvider;
use Bitrix\Main\Result;

final class SettingsHubBootstrapProvider
{
	public function __construct(
		private readonly SettingsPresentationProvider $settingsProvider,
		private readonly SettingsHubNavigationProvider $navigationProvider,
	)
	{
	}

	public function get(UserPermissions $permissions): Result
	{
		$settingsResult = $this->settingsProvider->get($permissions);
		if (!$settingsResult->isSuccess())
		{
			return $settingsResult;
		}

		$navigationResult = $this->navigationProvider->get($permissions);
		if (!$navigationResult->isSuccess())
		{
			return (new Result())->addErrors($navigationResult->getErrors());
		}

		return (new Result())->setData([
			...$settingsResult->getData(),
			...$navigationResult->getData(),
		]);
	}
}
