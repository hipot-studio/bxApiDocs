<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class TaskResultMessageTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TaskResultMessage_Query query()
 * @method static EO_TaskResultMessage_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TaskResultMessage_Result getById($id)
 * @method static EO_TaskResultMessage_Result getList(array $parameters = [])
 * @method static EO_TaskResultMessage_Entity getEntity()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TaskResultMessage createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TaskResultMessage_Collection createCollection()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TaskResultMessage wakeUpObject($row)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TaskResultMessage_Collection wakeUpCollection($rows)
 */
class TaskResultMessageTable extends DataManager
{
	use AddInsertIgnoreTrait;
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_tasks_result_message';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('RESULT_ID'))
				->configurePrimary(),
			(new IntegerField('MESSAGE_ID'))
				->configurePrimary(),
		];
	}
}

