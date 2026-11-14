<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Main\Localization\Loc;

final class SmartProcessStartForms extends AbstractCrmFlag
{
	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_FEATURE_SMART_PROCESS_START_FORMS_NAME');
	}

	public function enabledByDefault(): bool
	{
		return false;
	}
}
