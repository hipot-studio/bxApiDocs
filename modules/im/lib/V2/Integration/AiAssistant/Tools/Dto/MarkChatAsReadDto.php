<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\RegExp;

final class MarkChatAsReadDto
{
	public function __construct(
		#[NotEmpty]
		#[RegExp('/^(chat\d+|\d+)$/')]
		public ?string $dialogId = null,
	) {
	}

	public static function createFromParams(array $args): self
	{
		return new self(
			dialogId: isset($args['dialogId']) ? (string)$args['dialogId'] : null,
		);
	}
}
