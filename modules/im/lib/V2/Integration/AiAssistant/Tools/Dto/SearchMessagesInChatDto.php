<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Main\Validation\Rule\RegExp;

final class SearchMessagesInChatDto
{
	/**
	 * Optional pagination cursor. Stays uninitialized when omitted: the validation service skips
	 * uninitialized nullable properties, so the PositiveNumber rule applies only to a provided
	 * value — a non-positive cursor fails validation instead of silently restarting the search
	 * from the first page. Check with isset() before reading.
	 */
	#[PositiveNumber(errorMessage: 'lastId must be a positive message ID; omit it to get the first page of matches.')]
	public ?int $lastId;

	public function __construct(
		#[NotEmpty]
		#[RegExp('/^(chat\d+|\d+)$/')]
		public ?string $dialogId = null,

		#[NotEmpty]
		#[Length(min: 3, max: 500)]
		public ?string $query = null,

		#[Range(min: 1, max: 50)]
		public int $limit = 10,
	) {
	}

	public static function createFromParams(array $args): self
	{
		$dto = new self(
			dialogId: isset($args['dialogId']) ? (string)$args['dialogId'] : null,
			query: isset($args['query']) ? (string)$args['query'] : null,
			limit: isset($args['limit']) ? (int)$args['limit'] : 10,
		);

		if (isset($args['lastId']))
		{
			$dto->lastId = (int)$args['lastId'];
		}

		return $dto;
	}
}
