<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Models;

use Bitrix\Main\ORM;

/**
 * Class ReplacementsTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Replacements_Query query()
 * @method static EO_Replacements_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Replacements_Result getById($id)
 * @method static EO_Replacements_Result getList(array $parameters = [])
 * @method static EO_Replacements_Entity getEntity()
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Replacements createObject($setDefaultValues = true)
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection createCollection()
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Replacements wakeUpObject($row)
 * @method static \Bitrix\Anonymizer\Internal\Models\EO_Replacements_Collection wakeUpCollection($rows)
 */
class ReplacementsTable extends ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_anonymizer_replacements';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\IntegerField('QUEST_ID'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('CODE'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('TEXT'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('LABEL'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('START'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('END'))
				->configureRequired()
			,
		];
	}
}