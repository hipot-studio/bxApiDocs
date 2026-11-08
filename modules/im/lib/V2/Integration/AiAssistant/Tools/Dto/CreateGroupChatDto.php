<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;

final class CreateGroupChatDto
{
	/**
	 * @param array<int, mixed> $userIds Optional: an empty list creates a chat with only the author.
	 */
	public function __construct(
		#[NotEmpty]
		#[Length(max: 255)]
		public ?string $title = null,

		public array $userIds = [],

		#[Length(max: 2000)]
		public string $description = '',
	) {
	}

	public static function createFromParams(array $args): self
	{
		return new self(
			title: isset($args['title']) ? (string)$args['title'] : null,
			userIds: isset($args['userIds']) && is_array($args['userIds']) ? $args['userIds'] : [],
			description: isset($args['description']) ? (string)$args['description'] : '',
		);
	}
}
