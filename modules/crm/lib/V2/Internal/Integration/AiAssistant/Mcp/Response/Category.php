<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final readonly class Category implements JsonSerializable, Arrayable
{
	public function __construct(
		private ?int $id,
		private string $title,
		private int $entityTypeId,
		private int $sort,
		private bool $default,
		private bool $system,
	)
	{
	}

	public static function fromObject(\Bitrix\Crm\Category\Entity\Category $category): self
	{
		return new Category(
			id: $category->getId(),
			title: $category->getName(),
			entityTypeId: $category->getEntityTypeId(),
			sort: $category->getSort(),
			default: $category->getIsDefault(),
			system: $category->getIsSystem(),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'entityTypeId' => $this->entityTypeId,
			'sort' => $this->sort,
			'default' => $this->default,
			'system' => $this->system,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}