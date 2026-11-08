<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Main\Validation\Rule\RegExp;

final class GetLastMessagesDto
{
	/**
	 * Optional pagination cursor. Stays uninitialized when omitted: the validation service skips
	 * uninitialized nullable properties, so the PositiveNumber rule applies only to a provided
	 * value — a non-positive cursor fails validation instead of silently restarting from the
	 * first page. Check with isset() before reading.
	 */
	#[PositiveNumber(errorMessage: 'beforeMessageId must be a positive message ID; omit it to start from the most recent message.')]
	public ?int $beforeMessageId;

	public function __construct(
		#[NotEmpty]
		#[RegExp('/^(chat\d+|\d+)$/')]
		public ?string $dialogId = null,

		#[Range(min: 1, max: 50)]
		public int $limit = 20,
	) {
	}

	public static function createFromParams(array $args): self
	{
		$dto = new self(
			dialogId: isset($args['dialogId']) ? (string)$args['dialogId'] : null,
			limit: isset($args['limit']) ? (int)$args['limit'] : 20,
		);

		if (isset($args['beforeMessageId']))
		{
			$dto->beforeMessageId = (int)$args['beforeMessageId'];
		}

		return $dto;
	}
}
