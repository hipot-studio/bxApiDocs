<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class ConfigurationSectionDto extends Dto
{
	#[Editable]
	#[Required]
	#[NotEmpty(allowZero: true, allowSpaces: true)]
	public string $name;

	#[Editable]
	#[Required]
	public string $title;

	#[Editable]
	#[Required]
	#[ElementType(ConfigurationElementDto::class)]
	public DtoCollection $elements;

	public function toArray(bool $rawData = false): array
	{
		$data = parent::toArray($rawData);
		$data['elements'] = $this->elements->toArray();

		return $data;
	}
}
