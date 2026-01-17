<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Length;

final class SearchGroupChatsByNameDto
{
	public function __construct(
		#[NotEmpty]
		#[Length(min: 3, max: 500)]
		public ?string $query = null,
	) {
	}

	public static function createFromParams(array $args): self
	{
		return new self(
			query: (string)$args['query'],
		);
	}
}