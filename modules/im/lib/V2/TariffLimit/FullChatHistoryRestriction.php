<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\TariffLimit;

final class FullChatHistoryRestriction implements TariffRestriction
{
	public const CODE = 'fullChatHistory';

	public function __construct(
		private readonly bool $isAvailable,
		private readonly ?int $limitDays,
	) {}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function jsonSerialize(): array
	{
		return [
			'isAvailable' => $this->isAvailable,
			'limitDays' => $this->limitDays,
		];
	}
}
