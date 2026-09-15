<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Models;

use Bitrix\Main\ORM;

/**
 * Class QuestTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Quest_Query query()
 * @method static EO_Quest_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Quest_Result getById($id)
 * @method static EO_Quest_Result getList(array $parameters = [])
 * @method static EO_Quest_Entity getEntity()
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Quest createObject($setDefaultValues = true)
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection createCollection()
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Quest wakeUpObject($row)
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Quest_Collection wakeUpCollection($rows)
 */
class QuestTable extends ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_anonymizer_quest';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\StringField('PROVIDER_CLASS'))
				->configureRequired()
			,
			(new ORM\Fields\ArrayField('PROVIDER_DATA'))
				->configureNullable()
				->configureDefaultValue(null)
			,
			(new ORM\Fields\StringField('HANDLER_CLASS'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('MODULE_ID'))
				->configureRequired()
			,
		];
	}
}