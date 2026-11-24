<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\Update;

use Bitrix\Im\V2\Message;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;

class UpdateFields
{
	public function __construct(
		#[NotEmpty]
		#[Length(min: 1, max: Message::MESSAGE_MAX_LENGTH)]
		public readonly ?string $message,
	)
	{
	}

	public static function fromArray(array $data): self
	{
		$message = isset($data['message']) && is_string($data['message'])
			? trim($data['message'])
			: null
		;

		return new self(
			message: $message
		);
	}
}
