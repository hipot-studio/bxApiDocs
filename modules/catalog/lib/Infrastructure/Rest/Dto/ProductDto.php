<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\ProductMapper;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Product REST V3 DTO (iblock element + catalog product fields).
 * Dynamic iblock properties travel in {@see $properties} (keys PROPERTY_{id}).
 */
#[MappedBy(ProductMapper::class)]
class ProductDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public ?int $iblockId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $name;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $code;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $xmlId;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $active;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $sort;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?int $createdBy;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?int $modifiedBy;

	#[Filterable, Sortable]
	public ?DateTime $dateCreate;

	#[Filterable, Sortable]
	public ?DateTime $timestampX;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?DateTime $dateActiveFrom;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?DateTime $dateActiveTo;

	#[Editable(['add', 'update'])]
	public ?string $previewText;

	#[Editable(['add', 'update'])]
	public ?string $previewTextType;

	#[Editable(['add', 'update'])]
	public mixed $previewPicture;

	#[Editable(['add', 'update'])]
	public ?string $detailText;

	#[Editable(['add', 'update'])]
	public ?string $detailTextType;

	#[Editable(['add', 'update'])]
	public mixed $detailPicture;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?int $iblockSectionId;

	/** @var list<int>|null */
	#[Editable(['add', 'update'])]
	public ?array $iblockSection;

	#[Filterable, Sortable]
	public ?int $type;

	#[Filterable]
	public ?string $available;

	#[Filterable]
	public ?string $bundle;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?float $quantity;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?float $quantityReserved;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?string $quantityTrace;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?string $canBuyZero;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?string $subscribe;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?int $vatId;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?string $vatIncluded;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?float $purchasingPrice;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?string $purchasingCurrency;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?string $barcodeMulti;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?float $weight;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?float $length;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?float $width;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?float $height;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?int $measure;

	#[Editable(['add', 'update'])]
	public ?int $recurSchemeLength;

	#[Editable(['add', 'update'])]
	public ?string $recurSchemeType;

	#[Editable(['add', 'update'])]
	public ?int $trialPriceId;

	#[Editable(['add', 'update'])]
	public ?string $withoutOrder;

	#[Editable(['add', 'update'])]
	public ?string $quantityTraceRaw;

	#[Editable(['add', 'update'])]
	public ?string $paymentType;

	#[Editable(['add', 'update'])]
	public ?string $subscribeRaw;

	#[Editable(['add', 'update'])]
	public ?string $canBuyZeroRaw;

	/**
	 * Iblock property values. Keys: PROPERTY_{id} (Engine parity).
	 *
	 * @var array<string, mixed>|null
	 */
	#[Editable(['add', 'update'])]
	public ?array $properties;
}
