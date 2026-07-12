<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnCollectTariffRestrictions;

use Bitrix\Im\V2\TariffLimit\TariffRestriction;
use Bitrix\Main\Loader;

Loader::requireModule('im');

final class CollabRestriction implements TariffRestriction
{
	public const CODE = 'collab';

	public function __construct(
		private readonly bool $isAvailable,
		private readonly bool $isCopyAvailable,
	) {}

	public function getCode(): string
	{
		return self::CODE;
	}

	public function jsonSerialize(): array
	{
		return [
			'isAvailable' => $this->isAvailable,
			'isCopyAvailable' => $this->isCopyAvailable,
		];
	}
}
