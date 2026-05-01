<?php

namespace Bitrix\Intranet\Controller;

use Bitrix\Intranet\ActionFilter\UserType;

class Composite extends \Bitrix\Main\Engine\Controller
{
	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new UserType(['employee', 'extranet']),
			]
		);
	}

	public function clearCacheAction(): bool
	{
		if (\Bitrix\Main\Loader::includeModule('intranet'))
		{
			\Bitrix\Intranet\Composite\CacheProvider::deleteUserCache();

			return true;
		}

		return false;
	}
}
