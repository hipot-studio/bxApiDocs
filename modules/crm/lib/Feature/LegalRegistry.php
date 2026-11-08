<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Experimental;
use Bitrix\Main\Localization\Loc;

final class LegalRegistry extends AbstractCrmFlag
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_LEGAL_REGISTRY_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Experimental::getInstance();
	}

	public function enabledByDefault(): bool
	{
		return false;
	}

	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}
}
