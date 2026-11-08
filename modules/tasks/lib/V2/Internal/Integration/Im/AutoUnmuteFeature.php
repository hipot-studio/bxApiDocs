<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Main\Config\Option;

final class AutoUnmuteFeature
{
	private const MODULE_ID = 'tasks';

	private const OPTION_ENABLED = 'task_chat_auto_unmute_available';
	private const DEFAULT_ENABLED = 'N';

	public static function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_ENABLED, self::DEFAULT_ENABLED) === 'Y';
	}
}
