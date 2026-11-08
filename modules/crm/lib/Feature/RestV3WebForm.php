<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\RestV3;
use Bitrix\Crm\V2\Internal\Integration\Rest\V3\CacheManager;
use Bitrix\Main\Localization\Loc;

class RestV3WebForm extends AbstractCrmFlag
{
	public function getName(): string
	{
		return Loc::getMessage('REST_V3_WEB_FORM_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return RestV3::getInstance();
	}

	public function enabledByDefault(): bool
	{
		return false;
	}

	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}

	protected function onEnable(): void
	{
		parent::onEnable();

		CacheManager::cleanAll();
	}

	protected function onDisable(): void
	{
		parent::onDisable();

		CacheManager::cleanAll();
	}
}
