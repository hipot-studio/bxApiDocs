<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\BlockCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\ConnectionCollection;

class Draft implements EntityInterface
{
	public function __construct(
		private int $id,
		public readonly int $userId = 0,
		public readonly ?int $templateId = null,
		public readonly string $documentType = '',
		public readonly string $entity = '',
		public readonly BlockCollection $blocks = new BlockCollection(),
		public readonly ConnectionCollection $connections = new ConnectionCollection(),
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function setId(int $id): void
	{
		$this->id = $id;
	}

	public static function createFromArray(array $data): self
	{
		$blocks = new BlockCollection();
		$connections = new ConnectionCollection();

		if (isset($data['blocks']) && is_array($data['blocks']))
		{
			$blocks->fill($data['blocks']);
		}

		if (isset($data['connections']) && is_array($data['connections']))
		{
			$connections->fill($data['connections']);
		}

		return new static(
			$data['id'] ?? 0,
			$data['userId'] ?? 0,
			$data['templateId'] ?? null,
			$data['documentType'] ?? '',
			$data['entity'] ?? '',
			$blocks,
			$connections,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'templateId' => $this->templateId,
			'blocks' => $this->blocks->toArray(),
			'connections' => $this->connections->toArray(),
		];
	}
}
