<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\DateNavigation;

use Bitrix\Im\V2\Rest\RestConvertible;

class Calendar implements RestConvertible
{
	/**
	 * @param string[] $days Y-m-d days (in user time) having at least one visible message.
	 */
	public function __construct(
		private readonly array $days,
		private readonly ?string $firstAvailableDate,
		private readonly ?string $lastAvailableDate,
	){}

	public function getDays(): array
	{
		return $this->days;
	}

	public function getFirstAvailableDate(): ?string
	{
		return $this->firstAvailableDate;
	}

	public function getLastAvailableDate(): ?string
	{
		return $this->lastAvailableDate;
	}

	public static function getRestEntityName(): string
	{
		return 'calendar';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'days' => $this->days,
			'firstAvailableDate' => $this->firstAvailableDate,
			'lastAvailableDate' => $this->lastAvailableDate,
		];
	}
}
