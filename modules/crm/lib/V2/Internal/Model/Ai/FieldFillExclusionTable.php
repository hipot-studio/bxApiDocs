<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\Ai;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Sparse storage of the user fields BitrixGPT is not allowed to fill: a row forbids one field, the
 * absence of a row allows it. Nothing is written for a field left at its default, so a portal that
 * never touched the setting keeps the table empty.
 *
 * The entity the field belongs to is deliberately not stored. A reader needs the UF metadata of the
 * entity anyway - without it there are no `UF_CRM_*` names to answer with - so it already knows which
 * ids belong to the type and asks the storage about exactly those.
 *
 * @internal
 */
final class FieldFillExclusionTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_crm_ai_field_fill_exclusion';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('USER_FIELD_ID'))
				->configureRequired()
				->configureUnique()
			,
		];
	}
}
