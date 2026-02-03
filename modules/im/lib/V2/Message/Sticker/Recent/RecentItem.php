<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\Recent;

use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Main\Type\DateTime;

class RecentItem implements RestConvertible
{
	public function __construct(
		public readonly int $id,
		public readonly int $packId,
		public readonly PackType $packType,
		public readonly DateTime $dateCreate,
	)
	{}


	public static function getRestEntityName(): string
	{
		return 'recentItem';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'id' => $this->id,
			'packId' => $this->packId,
			'packType' => $this->packType->value,
		];
	}

	public function getUniqueKey(): string
	{
		return "{$this->packId}_{$this->packType->value}_{$this->id}";
	}
}
