<?php

namespace Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto;

use Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto;
use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Caster\ObjectCaster;
use Bitrix\Crm\Dto\Validator\ObjectField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class MenuItemDto extends \Bitrix\Crm\Dto\Dto
{
	public ?TextWithTranslationDto $title = null;
	public ?ActionDto $action = null;
	public ?string $scope = null;
	public ?bool $hideIfReadonly = null;
	public ?TextWithTranslationDto $subtitle = null;
	public ?string $design = null;
	public ?bool $isSelected = null;
	public ?bool $isLocked = null;
	public ?BadgeTextDto $badgeText = null;
	public ?string $sectionCode = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'badgeText' => new ObjectCaster(BadgeTextDto::class),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'title'),
			new Dto\Validator\TextWithTranslationField($this, 'title'),
			new RequiredField($this, 'action'),
			new ObjectField($this, 'action'),
			new Dto\Validator\ScopeField($this, 'scope'),
			new Dto\Validator\TextWithTranslationField($this, 'subtitle'),
			new Dto\Validator\DesignField($this, 'design'),
			new ObjectField($this, 'badgeText'),
		];
	}
}
