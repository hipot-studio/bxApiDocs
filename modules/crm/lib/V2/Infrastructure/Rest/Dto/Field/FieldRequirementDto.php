<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field;

use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Requirement;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;

final class FieldRequirementDto extends Dto
{
	public string $fieldId;
	public ?int $categoryId;
	public string $mode;

	#[ElementType('string')]
	public array $requiredStageIds;

	public bool $globallyRequired;

	public static function fromRequirement(Requirement $requirement): self
	{
		$dto = self::create();
		$dto->fieldId = $requirement->getFieldId();
		$dto->categoryId = $requirement->getCategoryId();
		$dto->mode = $requirement->getMode()->value;
		$dto->requiredStageIds = $requirement->getRequiredStageIds();
		$dto->globallyRequired = $requirement->isGloballyRequired();

		return $dto;
	}
}
