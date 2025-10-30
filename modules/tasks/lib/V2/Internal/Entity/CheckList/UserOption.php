<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\CheckList;

use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;

class UserOption extends AbstractEntity
{
	public function __construct(
		public readonly ?int $id = null,
		#[PositiveNumber]
		public readonly ?int $userId = null,
		#[PositiveNumber]
		public readonly ?int $itemId = null,
		#[PositiveNumber]
		#[InArray(Option::ALLOWED_OPTIONS)]
		public readonly ?int $code = null,
	){}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: $props['id'] ?? null,
			userId: $props['userId'] ?? null,
			itemId: $props['itemId'] ?? null,
			code: $props['code'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'itemId' => $this->itemId,
			'code' => $this->code,
		];
	}
}
