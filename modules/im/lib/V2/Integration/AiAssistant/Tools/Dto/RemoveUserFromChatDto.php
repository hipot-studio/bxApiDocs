<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\RegExp;

final class RemoveUserFromChatDto
{
	public function __construct(
		#[NotEmpty]
		#[RegExp('/^chat\d+$/')]
		public ?string $dialogId = null,

		#[PositiveNumber]
		public int $targetUserId = 0,

		public bool $confirmSelfLeaveOrOwnerChange = false,
	) {
	}

	public static function createFromParams(array $args): self
	{
		return new self(
			dialogId: isset($args['dialogId']) ? (string)$args['dialogId'] : null,
			targetUserId: isset($args['targetUserId']) ? (int)$args['targetUserId'] : 0,
			confirmSelfLeaveOrOwnerChange: isset($args['confirmSelfLeaveOrOwnerChange'])
				? (bool)$args['confirmSelfLeaveOrOwnerChange']
				: false,
		);
	}
}
