<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\Contract\Arrayable;

final class Connection implements EntityInterface, Arrayable
{
	public function __construct(
		public readonly string $id,
		public readonly string $sourceBlockId,
		public readonly string $sourcePortId,
		public readonly string $targetBlockId,
		public readonly string $targetPortId,
	)
	{
	}

	public static function createFromArray(array $data): self
	{
		return new Connection(
			(string)($data['id'] ?? 0),
			(string)($data['sourceBlockId'] ?? 0),
			(string)($data['sourcePortId'] ?? 0),
			(string)($data['targetBlockId'] ?? 0),
			(string)($data['targetPortId'] ?? 0),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'sourceBlockId' => $this->sourceBlockId,
			'sourcePortId' => $this->sourcePortId,
			'targetBlockId' => $this->targetBlockId,
			'targetPortId' => $this->targetPortId,
		];
	}

	public function getId(): string
	{
		return $this->id;
	}
}
