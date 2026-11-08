<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final readonly class Item implements Arrayable, JsonSerializable
{
	public function __construct(
		private int $id,
		private int $entityTypeId,
		private ?int $categoryId,
		private string $title,
		private ?string $stageId = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'entityTypeId' => $this->entityTypeId,
			'categoryId' => $this->categoryId,
			'title' => $this->title,
			'stageId' => $this->stageId,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public static function fromItem(\Bitrix\Crm\Item $item): self
	{
		return new self(
			id: $item->getId(),
			entityTypeId: $item->getEntityTypeId(),
			categoryId: $item->hasField(\Bitrix\Crm\Item::FIELD_NAME_CATEGORY_ID) ? $item->getCategoryId() : null,
			title: $item->getHeading(),
			stageId: $item->hasField(\Bitrix\Crm\Item::FIELD_NAME_STAGE_ID) ? $item->getStageId() : null,
		);
	}
}
