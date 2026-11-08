<?php

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\JsonField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;

/**
 * Class DeferredBatchTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DeferredBatch_Query query()
 * @method static EO_DeferredBatch_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DeferredBatch_Result getById($id)
 * @method static EO_DeferredBatch_Result getList(array $parameters = [])
 * @method static EO_DeferredBatch_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_DeferredBatch createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_DeferredBatch_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_DeferredBatch wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_DeferredBatch_Collection wakeUpCollection($rows)
 */
class DeferredBatchTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_rest_deferred_batch';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new StringField('STATUS'))
				->configureRequired()
				->configureDefaultValue(Status::Pending->value)
				->configureSize(20),

			(new JsonField('REQUEST_PARAMS'))
				->configureNullable(),

			(new JsonField('COMMANDS'))
				->configureRequired(),

			(new JsonField('SCOPES'))
				->configureRequired(),

			/** ID of the corresponding record in b_file (null until processing completes). */
			(new IntegerField('RESULT_FILE_ID'))
				->configureNullable(),

			(new TextField('ERROR_MESSAGE'))
				->configureNullable(),

			(new DatetimeField('CREATED_AT'))
				->configureRequired(),

			(new DatetimeField('UPDATED_AT'))
				->configureRequired(),
		];
	}

	public static function onBeforeAdd(Event $event): EventResult
	{
		$result = new EventResult();
		$now = new DateTime();
		$result->modifyFields([
			'CREATED_AT' => $now,
			'UPDATED_AT' => $now,
		]);

		return $result;
	}

	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();
		$result->modifyFields(['UPDATED_AT' => new DateTime()]);

		return $result;
	}
}
