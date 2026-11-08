<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\RegExp;

final class AddUsersToChatDto
{
	/**
	 * @param array<int, mixed> $userIds
	 */
	public function __construct(
		#[NotEmpty]
		#[RegExp('/^chat\d+$/')]
		public ?string $dialogId = null,

		#[NotEmpty]
		public array $userIds = [],

		public ?bool $hideHistory = null,
	) {
	}

	public static function createFromParams(array $args): self
	{
		return new self(
			dialogId: isset($args['dialogId']) ? (string)$args['dialogId'] : null,
			userIds: isset($args['userIds']) && is_array($args['userIds']) ? $args['userIds'] : [],
			hideHistory: isset($args['hideHistory']) ? (bool)$args['hideHistory'] : null,
		);
	}
}
