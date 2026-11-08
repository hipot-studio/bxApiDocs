<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Main\Localization\Loc;

final class SettingsHub extends AbstractCrmFlag
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_SETTINGS_HUB_NAME');
	}
}
