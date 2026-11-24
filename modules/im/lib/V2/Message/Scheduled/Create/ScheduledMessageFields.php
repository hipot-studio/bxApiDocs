<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\Create;

use Bitrix\Im\V2\Message;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\NotEmpty;
use DateTimeInterface;

class ScheduledMessageFields
{
	public function __construct(
		#[NotEmpty]
		#[Min(1)]
		public readonly ?int $chatId,

		#[NotEmpty]
		public readonly ?string $uuid,

		#[NotEmpty]
		#[Length(min: 1, max: Message::MESSAGE_MAX_LENGTH)]
		public readonly ?string $message,

		#[NotEmpty]
		public readonly ?DateTime $sendAt
	)
	{
	}

	public static function fromArray(array $data): self
	{
		$chatId =
			isset($data['chatId'])
			&& (
				is_int($data['chatId'])
				|| (
					is_string($data['chatId'])
					&& preg_match('/^[0-9]+$/D', $data['chatId']) === 1
				)
			)
				? (int)$data['chatId']
				: null
		;
		$uuid = isset($data['uuid']) && is_string($data['uuid'])
			? trim($data['uuid'])
			: null
		;
		$message = isset($data['message']) && is_string($data['message'])
			? trim($data['message'])
			: null
		;
		$sendAt = isset($data['sendAt']) && is_string($data['sendAt'])
			? DateTime::tryParse($data['sendAt'], DateTimeInterface::RFC3339)
			: null
		;

		return new self(
			chatId: $chatId,
			uuid: $uuid,
			message: $message,
			sendAt: $sendAt
		);
	}
}
