<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Model\Debugger;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;

/**
 * Class DebugTraceTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DebugTrace_Query query()
 * @method static EO_DebugTrace_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DebugTrace_Result getById($id)
 * @method static EO_DebugTrace_Result getList(array $parameters = [])
 * @method static EO_DebugTrace_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace_Collection wakeUpCollection($rows)
 */
class DebugTraceTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_bp_debug_trace';
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
			(new IntegerField('DEBUG_SESSION_ID'))
				->configureRequired()
				->configureSize(255)
			,
			(new StringField('KEY'))
				->configureRequired()
				->configureSize(255)
				->configureUnique()
			,
			(new StringField('TYPE'))
				->configureRequired()
				->configureSize(50)
			,
			(new StringField('MESSAGE'))
				->configureNullable()
				->configureSize(255)
			,
			(new TextField('DATA'))
				->configureNullable()
			,
			(new TextField('CONTEXT'))
				->configureNullable()
			,
			(new FloatField('TIMESTAMP'))
				->configureRequired()
				->configureScale(6)
			,
			(new DatetimeField('CREATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new Reference(
				'DEBUG_SESSION',
				DebugSessionTable::class,
				Join::on('this.DEBUG_SESSION_ID', 'ref.ID'),
			))
				->configureJoinType(Join::TYPE_LEFT)
			,
		];
	}
}
