<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Integration;
use Bitrix\Main\Localization\Loc;

final class CreateRequisiteBizprocActivity extends AbstractCrmFlag
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_CREATE_REQUISITE_BIZPROC_ACTIVITY_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Integration::getInstance();
	}
}
