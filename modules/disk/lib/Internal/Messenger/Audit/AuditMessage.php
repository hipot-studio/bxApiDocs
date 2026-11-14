<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Messenger\Audit;

use Bitrix\Disk\Internal\Entity;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;

final class AuditMessage extends AbstractMessage
{
	public function __construct(
		private readonly Entity\Audit\AuditMessage $payload,
	)
	{
	}

	public static function createFromData(array $data): MessageInterface
	{
		return new self(
			Entity\Audit\AuditMessage::createFromData($data['payload'] ?? $data),
		);
	}

	public function getPayload(): Entity\Audit\AuditMessage
	{
		return $this->payload;
	}

	public function jsonSerialize(): array
	{
		return [
			'payload' => $this->payload->jsonSerialize(),
		];
	}
}
