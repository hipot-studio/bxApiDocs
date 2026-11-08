<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Main\Validation\Rule\RegExp;

final class GetChatMembersDto
{
	public function __construct(
		#[NotEmpty]
		#[RegExp('/^(chat\d+|\d+)$/')]
		public ?string $dialogId = null,

		#[Range(min: 1, max: 100)]
		public int $limit = 50,

		public ?array $cursor = null,
	) {
	}

	public static function createFromParams(array $args): self
	{
		return new self(
			dialogId: isset($args['dialogId']) ? (string)$args['dialogId'] : null,
			limit: isset($args['limit']) ? (int)$args['limit'] : 50,
			cursor: isset($args['cursor']) && is_array($args['cursor']) ? $args['cursor'] : null,
		);
	}
}
