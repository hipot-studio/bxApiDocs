<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

final class AppChatEnsureResult
{
	public function __construct(
		public readonly int $chatId,
		public readonly bool $bindingChanged,
	) {}
}
