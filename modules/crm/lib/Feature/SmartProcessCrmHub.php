<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Experimental;
use Bitrix\Main\Localization\Loc;

class SmartProcessCrmHub extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('SMART_PROCESS_CRM_HUB_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Experimental::getInstance();
	}

	// crm.features.list handles the enableFeature GET before checkAccess() and without a CSRF token,
	// so a secret link would let a lured CRM admin turn the hub on for the whole portal.
	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}
}
