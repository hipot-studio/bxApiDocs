<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class LayoutDto extends Dto
{
	public string $id;

	#[Editable(['add', 'update'])]
	#[Required(['add'])]
	#[ElementType(ConfigurationSectionDto::class)]
	public DtoCollection $configuration;

	#[Editable(['add', 'update'])]
	#[Required(['add'])]
	public ?string $name = null;

	#[Editable(['add', 'update'])]
	#[Required(['add'])]
	#[ElementType(AudienceItemDto::class)]
	public ?DtoCollection $audience = null;

	public function toArray(bool $rawData = false): array
	{
		$data = parent::toArray($rawData);
		$data['configuration'] = $this->configuration->toArray();

		if ($this->audience === null)
		{
			$data['audience'] = null;
		}
		else
		{
			$data['audience'] = $this->audience->toArray();
		}

		return $data;
	}
}
