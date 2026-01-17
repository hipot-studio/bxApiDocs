<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\Tools\Dto;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\RegExp;

final class SendMessageDto
{
	public function __construct(
		#[NotEmpty]
		#[RegExp('/^(chat\d+|\d+)$/')]
		public ?string $dialogId = null,

		#[NotEmpty]
		#[Length(min: 1, max: 4096)]
		public ?string $message = null,
	) {
	}

	public static function createFromParams(array $args): self
	{
		return new self(
			dialogId: (string)$args['dialogId'],
			message: (string)$args['message'],
		);
	}
}
