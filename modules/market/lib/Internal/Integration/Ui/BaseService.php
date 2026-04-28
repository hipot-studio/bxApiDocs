<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Ui;

use Bitrix\Main;

abstract class BaseService
{
	protected static ?bool $isAvailable = null;

	protected static function isAvailable(): bool
	{
		return static::$isAvailable ??= Main\Loader::includeModule('ui');
	}
}
