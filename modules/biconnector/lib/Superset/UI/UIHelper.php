<?php

namespace Bitrix\BIConnector\Superset\UI;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Bitrix24;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class UIHelper
{
	public static function needShowDeleteInstanceWarning(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		if (!Bitrix24\CurrentUser::get()->isAdmin())
		{
			return false;
		}

		if (!SupersetInitializer::isSupersetExist())
		{
			return false;
		}

		if (!Feature::isBuilderEnabled())
		{
			return true;
		}

		$lockNotice = (int)Option::get('bitrix24', '~license_lock_notice', 0);

		return
			$lockNotice > 0
			&& time() > $lockNotice
		;
	}
}
