<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Im\Chat;

use Bitrix\Main\Loader;

/**
 * How long one message of the messenger may be.
 *
 * Asked of `im` itself so that the limit the text is cut by is the live one (AC-017). Without the
 * module there is nowhere to send anything either, so the fallback keeps the method total instead of
 * refusing: the caller is given the value CRM states for itself.
 *
 * @internal
 */
class MessageLengthLimit
{
	public function characters(int $fallback): int
	{
		return Loader::includeModule('im')
			? \Bitrix\Im\V2\Message::MESSAGE_MAX_LENGTH
			: $fallback
		;
	}
}
