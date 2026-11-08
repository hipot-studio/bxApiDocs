<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\Activities;
use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Main\Localization\Loc;

final class TimelineActivitySync extends AbstractCrmFlag
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_TIMELINE_ACTIVITY_SYNC_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Activities::getInstance();
	}

	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}
}
