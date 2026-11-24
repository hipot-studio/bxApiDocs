<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled\GetList;

use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\NotEmpty;
use DateTimeInterface;

class ScheduledMessageCursor implements RestConvertible
{
	public function __construct(
		#[NotEmpty]
		private readonly ?DateTime $sendAt,

		#[NotEmpty]
		#[Min(1)]
		private readonly ?int $scheduledMessageId
	)
	{
	}

	public static function getRestEntityName(): string
	{
		return 'scheduledMessageCursor';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'sendAt' => $this->getSendAt()?->format('c'),
			'scheduledMessageId' => $this->getScheduledMessageId(),
		];
	}

	public function getSendAt(): ?DateTime
	{
		return $this->sendAt;
	}

	public function getScheduledMessageId(): ?int
	{
		return $this->scheduledMessageId;
	}

	public static function fromArray(array $data): self
	{
		$sendAt = isset($data['sendAt']) && is_string($data['sendAt'])
			? DateTime::tryParse($data['sendAt'], DateTimeInterface::RFC3339)
			: null
		;
		$scheduledMessageId =
			isset($data['scheduledMessageId'])
			&& (
				is_int($data['scheduledMessageId'])
				|| (
					is_string($data['scheduledMessageId'])
					&& preg_match('/^[0-9]+$/D', $data['scheduledMessageId']) === 1
				)
			)
				? (int)$data['scheduledMessageId']
				: null
		;

		return new self(
			sendAt: $sendAt,
			scheduledMessageId: $scheduledMessageId
		);
	}
}
