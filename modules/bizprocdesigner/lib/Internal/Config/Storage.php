<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Config;

use Bitrix\Main;
use Bitrix\BizprocDesigner\Internal\Trait\SingletonTrait;
use Bitrix\Main\ArgumentOutOfRangeException;

class Storage
{
	use SingletonTrait;
	private const SEARCH_FIELDS_INDEXED = 'search_fields_indexed';

	private const MODULE_NAME = 'bizprocdesigner';

	/**
	 * @return bool
	 */
	public function isSearchFieldsIndexed(): bool
	{
		return $this->getOptionValue(self::SEARCH_FIELDS_INDEXED, 'N') === 'Y';
	}

	/**
	 * @param bool $indexed
	 *
	 * @return void
	 * @throws ArgumentOutOfRangeException
	 */
	public function setSearchFieldsIndexed(bool $indexed): void
	{
		Main\Config\Option::set(self::MODULE_NAME, self::SEARCH_FIELDS_INDEXED, $indexed ? 'Y' : 'N');
	}

	private function getOptionValue(string $option, mixed $defaultValue)
	{
		return Main\Config\Option::get(self::MODULE_NAME, $option, $defaultValue);
	}

}