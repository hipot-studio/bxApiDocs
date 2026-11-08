<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

final class CompareAndSetAppChatResult
{
	public function __construct(
		public readonly bool $succeeded,
		public readonly bool $changed,
	) {}
}
