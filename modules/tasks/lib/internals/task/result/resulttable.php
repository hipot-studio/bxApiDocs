<?php

namespace Bitrix\Tasks\Internals\Task\Result;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\UserTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Model\TaskResultMessageTable;
use Bitrix\Tasks\V2\Internal\Repository\TaskResultRepositoryInterface;

/**
 * Class ResultTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Result_Query query()
 * @method static EO_Result_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Result_Result getById($id)
 * @method static EO_Result_Result getList(array $parameters = [])
 * @method static EO_Result_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\Result\Result createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\Task\Result\EO_Result_Collection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\Result\Result wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\Task\Result\EO_Result_Collection wakeUpCollection($rows)
 */
class ResultTable extends DataManager
{
	public const UF_FILE_NAME = 'UF_RESULT_FILES';
	public const UF_PREVIEW_NAME = 'UF_RESULT_PREVIEW';

	public const STATUS_OPENED = 0;
	public const STATUS_CLOSED = 1;

	public static function getTableName(): string
	{
		return 'b_tasks_result';
	}

	public static function getUfId(): string
	{
		return 'TASKS_TASK_RESULT';
	}

	public static function getClass(): string
	{
		return static::class;
	}

	public static function getObjectClass(): string
	{
		return Result::class;
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('TASK_ID'))
				->configureRequired(),

			(new IntegerField('COMMENT_ID'))
				->configureRequired(),

			(new IntegerField('CREATED_BY'))
				->configureRequired(),

			new DatetimeField('CREATED_AT'),

			new DatetimeField('UPDATED_AT'),

			(new TextField('TEXT'))
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode']),

			new IntegerField('STATUS'),

			(new Reference(
				'USER',
				UserTable::class,
				Join::on('this.CREATED_BY', 'ref.ID')
			))
				->configureJoinType(Join::TYPE_INNER),

			(new Reference(
				'TASK',
				TaskTable::class,
				Join::on('this.TASK_ID', 'ref.ID')
			)),

			(new Reference(
				'MESSAGE',
				TaskResultMessageTable::class,
				Join::on('this.ID', 'ref.RESULT_ID')
			)),
		];
	}

	/**
	 * @deprecated
	 * @use TaskResultRepositoryInterface::getByTaskId()
	 */
	public static function getByTaskId(int $taskId): EO_Result_Collection
	{
		return Container::getInstance()->getResultRepository()->getByTaskId($taskId);
	}
}
