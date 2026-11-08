<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\V2\Relation\Reason;

final class ChatMember
{
	public function __construct(
		public readonly Reason $reason,
		public readonly bool $isHidden,
	) {}
}
