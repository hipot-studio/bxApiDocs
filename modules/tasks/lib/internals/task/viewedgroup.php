<?php

namespace Bitrix\Tasks\Internals\Task;

use Bitrix\Tasks\Internals\TaskDataManager;

/**
 * Class ViewedTable
 *
 * @package Bitrix\Tasks\Internals\Task
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ViewedGroup_Query query()
 * @method static EO_ViewedGroup_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ViewedGroup_Result getById($id)
 * @method static EO_ViewedGroup_Result getList(array $parameters = [])
 * @method static EO_ViewedGroup_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\EO_ViewedGroup createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\Task\EO_ViewedGroup_Collection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\EO_ViewedGroup wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\Task\EO_ViewedGroup_Collection wakeUpCollection($rows)
 */
class ViewedGroupTable extends TaskDataManager
{
	// TODO: useless table? drop it?
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_tasks_viewed_group';
	}

	/**
	 * @return false|string
	 */
	public static function getClass()
	{
		return static::class;
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return [

			'GROUP_ID' => [
				'data_type' => 'integer',
				'primary' => true,
			],
			'MEMBER_TYPE' => array(
				'data_type' => 'string',
				'primary' => true,
			),
			'USER_ID' => array(
				'data_type' => 'integer',
				'primary' => true,
			),
			'TYPE_ID' => array(
				'data_type' => 'integer',
				'primary' => true,
			),
			'VIEWED_DATE' => [
				'data_type' => 'datetime',
				'required' => true,
			]
		];
	}
}
