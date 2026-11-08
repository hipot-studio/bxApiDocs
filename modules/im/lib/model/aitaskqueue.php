<?php
namespace Bitrix\Im\Model;

use Bitrix\Im\V2\Common\DeleteTrait;
use Bitrix\Im\V2\Common\MultiplyInsertTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;

/**
 * Stores deduplication/state records for AI operations of the im module.
 * Do not confuse with the b_ai_queue table of the ai module — that one is a job queue.
 */
class AiTaskQueueTable extends DataManager
{
	use MultiplyInsertTrait;
	use DeleteTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_im_ai_task_queue';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			'ID' => [
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			],
			'ENTITY_TYPE' => [
				'data_type' => 'string',
				'required' => true,
			],
			'ENTITY_ID' => [
				'data_type' => 'string',
				'required' => true,
			],
			'PARAMS' => [
				'data_type' => 'text',
			],
			'DATE_CREATE' => [
				'data_type' => 'datetime',
				'required' => true,
				'default_value' => [__CLASS__, 'getCurrentDate'],
			],
		];
	}

	public static function getCurrentDate(): DateTime
	{
		return new DateTime();
	}
}
