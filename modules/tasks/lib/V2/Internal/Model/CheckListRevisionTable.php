<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class CheckListRevisionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CheckListRevision_Query query()
 * @method static EO_CheckListRevision_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CheckListRevision_Result getById($id)
 * @method static EO_CheckListRevision_Result getList(array $parameters = [])
 * @method static EO_CheckListRevision_Entity getEntity()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_CheckListRevision createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_CheckListRevision_Collection createCollection()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_CheckListRevision wakeUpObject($row)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_CheckListRevision_Collection wakeUpCollection($rows)
 */
class CheckListRevisionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_tasks_checklist_revision';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ENTITY_ID'))->configurePrimary(),
			(new StringField('ENTITY_TYPE'))->configurePrimary()->configureSize(50),
			(new IntegerField('REVISION'))->configureRequired()->configureDefaultValue(0),
		];
	}
}
