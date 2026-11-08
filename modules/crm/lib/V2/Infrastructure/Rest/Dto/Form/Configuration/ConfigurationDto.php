<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Configuration;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

#[Title('CRM form configuration')]
final class ConfigurationDto extends Dto
{
	/** @var DtoCollection<EntitySchemeDto> */
	#[ElementType(EntitySchemeDto::class)]
	public DtoCollection $entitySchemes;

	/** @var DtoCollection<NamedCodeDto> */
	#[ElementType(NamedCodeDto::class)]
	public DtoCollection $duplicateModes;

	public LeadDto $lead;

	/** @var DtoCollection<NamedIdDto> */
	#[ElementType(NamedIdDto::class)]
	public DtoCollection $dealCategories;

	/** @var DtoCollection<SmartProcessDto> */
	#[ElementType(SmartProcessDto::class)]
	public DtoCollection $smartProcesses;

	/** @var DtoCollection<NamedCodeDto> */
	#[ElementType(NamedCodeDto::class)]
	public DtoCollection $languages;

	/** @var DtoCollection<CurrencyDto> */
	#[ElementType(CurrencyDto::class)]
	public DtoCollection $currencies;

	/** @var DtoCollection<FieldTypeDto> */
	#[ElementType(FieldTypeDto::class)]
	public DtoCollection $fieldTypes;

	/**
	 * The pay systems a submission of a form may be paid through, and the set every form switches
	 * off from. Empty on a portal without the sale module.
	 *
	 * @var DtoCollection<NamedIdDto>
	 */
	#[ElementType(NamedIdDto::class)]
	public DtoCollection $paySystems;

	public PermissionsDto $permissions;

	public LimitsDto $limits;

	public ProductDto $product;

	public string $region;
}
