<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\RestV3;
use Bitrix\Crm\V2\Internal\Integration\Rest\V3\CacheManager;
use Bitrix\Main\Localization\Loc;

class RestV3Tracking extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('REST_V3_TRACKING_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return RestV3::getInstance();
	}

	public function enable(): void
	{
		parent::enable();

		CacheManager::cleanAll();
	}

	public function disable(): void
	{
		parent::disable();

		CacheManager::cleanAll();
	}

	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}
}
