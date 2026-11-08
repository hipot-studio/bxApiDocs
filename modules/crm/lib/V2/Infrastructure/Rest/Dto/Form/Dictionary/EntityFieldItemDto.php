<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * One value of a CRM field that enumerates its values.
 */
final class EntityFieldItemDto extends Dto
{
	/** The stored value of the item; what a submitted form carries. */
	public string $id;

	/** The name of the item as the portal keeps it; nothing is escaped here. */
	public string $label;
}
