<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\DateNavigation;

use Bitrix\Im\V2\Rest\RestConvertible;

class ResolvedDate implements RestConvertible
{
	public function __construct(
		private readonly int $messageId,
		private readonly string $date,
	){}

	public function getMessageId(): int
	{
		return $this->messageId;
	}

	public function getDate(): string
	{
		return $this->date;
	}

	public static function getRestEntityName(): string
	{
		return 'resolvedDate';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'messageId' => $this->messageId,
			'date' => $this->date,
		];
	}
}
