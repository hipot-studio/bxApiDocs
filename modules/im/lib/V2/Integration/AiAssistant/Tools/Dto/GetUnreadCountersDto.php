<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\Range;
use Bitrix\Main\Validation\Rule\RegExp;

final class GetUnreadCountersDto
{
	private const INVALID_DIALOG_ID_MESSAGE = 'Invalid dialog id. Use the user ID for a private chat (e.g. "123") '
		. 'or the chat ID prefixed with "chat" for a group chat (e.g. "chat456"), or omit dialogId to get '
		. 'the all-chats summary.';

	/**
	 * Optional dialog id. Stays uninitialized when omitted: the validation service skips uninitialized nullable
	 * properties, so the RegExp rule applies only to a provided value. Check with isset() before reading.
	 */
	#[RegExp('/^(chat\d+|\d+)$/', errorMessage: self::INVALID_DIALOG_ID_MESSAGE)]
	public ?string $dialogId;

	public function __construct(
		public bool $includeNotifications = true,

		#[Range(min: 1, max: 200)]
		public int $limit = 100,
	) {
	}

	public static function createFromParams(array $args): self
	{
		$dto = new self(
			includeNotifications: isset($args['includeNotifications']) ? (bool)$args['includeNotifications'] : true,
			limit: isset($args['limit']) ? (int)$args['limit'] : 100,
		);

		if (isset($args['dialogId']))
		{
			$dto->dialogId = (string)$args['dialogId'];
		}

		return $dto;
	}
}
