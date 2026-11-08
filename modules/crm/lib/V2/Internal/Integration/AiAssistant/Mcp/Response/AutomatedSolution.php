<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

use Bitrix\Main\Type\Contract\Arrayable;

final readonly class AutomatedSolution implements \JsonSerializable, Arrayable
{
	public function __construct(
		private int $id,
		private string $title,
		private array $entityTypeIds = [],
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'entityTypeIds' => $this->entityTypeIds,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public static function tryFromArray(array $automatedSolution): ?self
	{
		$id = $automatedSolution['ID'] ?? null;
		if (!is_numeric($id) || (int)$id <= 0)
		{
			return null;
		}

		$title = $automatedSolution['TITLE'] ?? null;
		if (empty($title))
		{
			return null;
		}

		$entityTypeIds = $automatedSolution['ENTITY_TYPE_IDS'] ?? null;
		if (!is_array($entityTypeIds))
		{
			return null;
		}

		return new self(
			id: (int)$id,
			title: $title,
			entityTypeIds: $entityTypeIds,
		);
	}
}
