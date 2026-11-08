<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary;

use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * A group the form scenarios are offered in.
 *
 * The dictionary is answered whole, so nothing here is filterable or sortable.
 */
#[Title('CRM form scenario category')]
final class ScenarioCategoryDto extends Dto
{
	/** The code of the group, as {@see ScenarioDto::$categoryId} names it. */
	public string $id;

	/** The name of the group as the portal keeps it; nothing is escaped here. */
	public string $title;
}
