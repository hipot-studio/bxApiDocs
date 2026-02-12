<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class BitrixEngineTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_BitrixEngin_Query query()
 * @method static EO_BitrixEngin_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_BitrixEngin_Result getById($id)
 * @method static EO_BitrixEngin_Result getList(array $parameters = [])
 * @method static EO_BitrixEngin_Entity getEntity()
 * @method static \Bitrix\AI\Engine\Model\EO_BitrixEngin createObject($setDefaultValues = true)
 * @method static \Bitrix\AI\Engine\Model\EO_BitrixEngin_Collection createCollection()
 * @method static \Bitrix\AI\Engine\Model\EO_BitrixEngin wakeUpObject($row)
 * @method static \Bitrix\AI\Engine\Model\EO_BitrixEngin_Collection wakeUpCollection($rows)
 */
class BitrixEngineTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_ai_bitrix_engine';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configureAutocomplete()
				->configurePrimary(),

			(new StringField('CLASS'))
				->configureRequired(),

			(new StringField('CATEGORY'))
				->configureRequired(),

			(new BooleanField('IS_ACTIVE'))
				->configureValues(0, 1)
				->configureDefaultValue(1),
		];
	}
}
