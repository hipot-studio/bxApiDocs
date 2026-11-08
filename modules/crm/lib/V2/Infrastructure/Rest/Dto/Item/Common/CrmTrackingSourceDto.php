<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The source of the item in the cross-channel analytics: the source of the trace of the last binding
 * of the item. Published apart from {@see CrmTrackingDto}, because an own company keeps its UTM tags
 * but has no source.
 */
class CrmTrackingSourceDto extends Dto
{
	#[Editable]
	#[Filterable]
	public ?int $trackingSourceId;
}
