<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\Debugger;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\OneToMany;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;


/**
 * Class DebugSessionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DebugSession_Query query()
 * @method static EO_DebugSession_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DebugSession_Result getById($id)
 * @method static EO_DebugSession_Result getList(array $parameters = [])
 * @method static EO_DebugSession_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession_Collection wakeUpCollection($rows)
 */
class DebugSessionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_bp_debug_session';
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
			,
			(new IntegerField('DEBUG_ID'))
				->configureRequired()
			,
			(new StringField('MODULE_ID'))
				->configureNullable()
				->configureSize(32)
			,
			(new StringField('ENTITY'))
				->configureNullable()
				->configureSize(64)
			,
			(new StringField('DOCUMENT_ID'))
				->configureNullable()
				->configureSize(128),

			(new StringField('WORKFLOW_ID'))
				->configureNullable()
				->configureSize(32)
			,

			(new IntegerField('TEMPLATE_ID'))
				->configureNullable()
			,
			(new FloatField('START_TIME'))
				->configureRequired()
				->configureScale(6)
			,
			(new FloatField('END_TIME'))
				->configureNullable()
				->configureScale(6)
			,
			(new TextField('METADATA'))
				->configureNullable(),
			(new TextField('LOGS'))
				->configureNullable()
			,
			(new TextField('METRICS'))
				->configureNullable()
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new OneToMany(
				'DEBUG_TRACES',
				DebugTraceTable::class,
				'DEBUG_SESSION',
			)),
			(new Reference(
				'DEBUG',
				DebugTable::class,
				Join::on('this.DEBUG_ID', 'ref.ID'),
			))
				->configureJoinType(Join::TYPE_LEFT)
			,
		];
	}

	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();

		$result->modifyFields([
			'UPDATED_AT' => new DateTime(),
		]);

		return $result;
	}
}
