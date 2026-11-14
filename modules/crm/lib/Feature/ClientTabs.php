<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Main\Localization\Loc;

/**
 * The "Contacts" and "Companies" tabs of the item cards and the names of the relation tabs that go with them.
 * The client binding API and its controllers do not depend on the flag.
 */
final class ClientTabs extends AbstractCrmFlag
{
	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_FEATURE_CLIENT_TABS_NAME');
	}

	public function enabledByDefault(): bool
	{
		return false;
	}
}
