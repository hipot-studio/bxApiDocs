<?php

namespace Bitrix\DocumentGenerator\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

/**
 * Class ActualizeQueueTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ActualizeQueue_Query query()
 * @method static EO_ActualizeQueue_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ActualizeQueue_Result getById($id)
 * @method static EO_ActualizeQueue_Result getList(array $parameters = [])
 * @method static EO_ActualizeQueue_Entity getEntity()
 * @method static \Bitrix\DocumentGenerator\Model\EO_ActualizeQueue createObject($setDefaultValues = true)
 * @method static \Bitrix\DocumentGenerator\Model\EO_ActualizeQueue_Collection createCollection()
 * @method static \Bitrix\DocumentGenerator\Model\EO_ActualizeQueue wakeUpObject($row)
 * @method static \Bitrix\DocumentGenerator\Model\EO_ActualizeQueue_Collection wakeUpCollection($rows)
 */
class ActualizeQueueTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_documentgenerator_actualize_queue';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('DOCUMENT_ID'))
				->configurePrimary()
			,
			(new IntegerField('USER_ID'))
				->configureNullable(),
			(new DatetimeField('ADDED_TIME'))
				->configureDefaultValue(static function() {
					return new DateTime();
				})
			,
		];
	}
}
