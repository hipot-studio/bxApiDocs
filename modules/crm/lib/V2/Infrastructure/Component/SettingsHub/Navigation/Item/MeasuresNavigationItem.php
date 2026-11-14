<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\V2\Internal\Access\Measure\MeasureAccess;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class MeasuresNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(private MeasureAccess $access)
	{
	}

	public function id(): string
	{
		return 'measures';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin()
			&& Loader::includeModule('catalog')
			&& AccessController::getCurrent()->check(ActionDictionary::ACTION_MEASURE_EDIT);
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->access->canEdit())
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_MEASURES_ACCESS'),
					'CRM_SETTINGS_HUB_NAVIGATION_MEASURES_ACCESS',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/measure/');
	}
}
