<?php

declare(strict_types=1);

/**
 * This class is for internal use only, not a part of public API.
 * It can be changed at any time without notification.
 *
 * @access private
 */

namespace Bitrix\Tasks\Integration\IM;

final class ChatEntity
{
	public function __construct(
		public readonly string $type,
		public readonly string $id,
	)
	{
	}
}
