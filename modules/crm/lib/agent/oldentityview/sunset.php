<?php

namespace Bitrix\Crm\Agent\OldEntityView;

use Bitrix\Crm\Component\DisableHelpers\OldEntityViewDisableHelper;

class Sunset
{
	public static function run(): void
	{
		OldEntityViewDisableHelper::migrateToNewLayout();
	}
}
