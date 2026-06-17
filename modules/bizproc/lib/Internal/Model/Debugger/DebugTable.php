<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\Debugger;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class DebugTable
 *
 * ORM table for b_bp_debug.
 * One user can debug only ONE template at a time (UNIQUE constraint on USER_ID, TEMPLATE_ID).
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Debug_Query query()
 * @method static EO_Debug_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Debug_Result getById($id)
 * @method static EO_Debug_Result getList(array $parameters = [])
 * @method static EO_Debug_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug_Collection wakeUpCollection($rows)
 */
class DebugTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_bp_debug';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureTitle('ID')
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
				->configureTitle('User ID')
			,
			(new IntegerField('TEMPLATE_ID'))
				->configureRequired()
				->configureTitle('Template ID')
			,
			(new StringField('MODULE_ID'))
				->configureNullable()
				->configureSize(32)
				->configureTitle('Module ID')
			,
			(new StringField('ENTITY'))
				->configureNullable()
				->configureSize(64)
				->configureTitle('Entity')
			,
			(new StringField('DOCUMENT_ID'))
				->configureNullable()
				->configureSize(128)
				->configureTitle('Document ID'),
			(new StringField('ENABLED'))
				->configureRequired()
				->configureSize(1)
				->configureDefaultValue('Y')
				->configureTitle('Enabled')
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
				->configureTitle('Created At')
			,
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
				->configureTitle('Updated At')
			,
		];
	}
}
