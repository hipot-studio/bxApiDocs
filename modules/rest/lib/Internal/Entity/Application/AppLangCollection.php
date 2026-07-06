<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main;

class AppLangCollection extends Main\Entity\EntityCollection
{
	protected static function getEntityClass(): string
	{
		return AppLang::class;
	}

	public function getMenuNames(): array
	{
		$result = [];
		foreach ($this->getIterator() as $appLang)
		{
			$result[$appLang->getLanguageId()] = $appLang->getMenuName();
		}

		return $result;
	}
}
