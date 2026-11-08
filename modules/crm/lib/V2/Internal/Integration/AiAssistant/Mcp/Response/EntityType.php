<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final readonly class EntityType implements JsonSerializable, Arrayable
{
	public function __construct(
		public int $entityTypeId,
		public string $title,
		public bool $isCategoriesEnabled,
		public bool $isStagesEnabled,
		public ?int $automatedSolutionId,
	)
	{
	}

	public static function fromFactory(Factory $factory): self
	{
		$isCategoriesEnabled = $factory->getEntityTypeId() === \CCrmOwnerType::Deal;

		return new self(
			entityTypeId: $factory->getEntityTypeId(),
			title: $factory->getEntityDescription(),
			isCategoriesEnabled: $isCategoriesEnabled,
			isStagesEnabled: $factory->isStagesEnabled(),
			automatedSolutionId: null,
		);
	}

	public static function fromDynamicType(Type $type): self
	{
		return new self(
			entityTypeId: (int)$type->getEntityTypeId(),
			title: (string)$type->getTitle(),
			isCategoriesEnabled: (bool)$type->getIsCategoriesEnabled(),
			isStagesEnabled: (bool)$type->getIsStagesEnabled(),
			automatedSolutionId: $type->getCustomSectionId(),
		);
	}

	public function toArray(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'title' => $this->title,
			'isCategoriesEnabled' => $this->isCategoriesEnabled,
			'isStagesEnabled' => $this->isStagesEnabled,
			'automatedSolutionId' => $this->automatedSolutionId,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
