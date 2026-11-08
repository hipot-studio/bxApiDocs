<?php

namespace Bitrix\Intranet\Internal\Repository;

use Bitrix\Main\Localization\LanguageTable;

class LanguageRepository
{
	public function getPortalLanguages(): array
	{
		return LanguageTable::getList([
			'select' => ['ID', 'NAME', 'CODE'],
			'filter'=> ['=ACTIVE' => 'Y'],
			'order'=> ['NAME' => 'ASC'],
			'cache' => ['ttl' => 8640000],
		])->fetchAll();
	}
}