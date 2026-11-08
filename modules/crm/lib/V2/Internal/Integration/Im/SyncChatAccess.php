<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Im;

use Bitrix\Im\Dialog;
use Bitrix\Main\Loader;
use Closure;

/**
 * Whether a user may open the chat of a Sync activity.
 *
 * The class is not final: the closure is the seam of the tests.
 *
 * @internal
 */
class SyncChatAccess
{
	private const MODULE_ID = 'im';

	private Closure $hasAccess;

	public function __construct(?Closure $hasAccess = null)
	{
		$this->hasAccess = $hasAccess
			?? static fn(string $dialogId, int $userId): bool
				=> Loader::includeModule(self::MODULE_ID) && Dialog::hasAccess($dialogId, $userId)
		;
	}

	public function canOpen(int $chatId, int $userId): bool
	{
		if ($chatId <= 0 || $userId <= 0)
		{
			return false;
		}

		return (bool)($this->hasAccess)("chat{$chatId}", $userId);
	}
}
