<?php
namespace Bitrix\Tasks;

use \Bitrix\Main\Entity;

/**
 * Class ProjectsTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Projects_Query query()
 * @method static EO_Projects_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Projects_Result getById($id)
 * @method static EO_Projects_Result getList(array $parameters = [])
 * @method static EO_Projects_Entity getEntity()
 * @method static \Bitrix\Tasks\EO_Projects createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\EO_Projects_Collection createCollection()
 * @method static \Bitrix\Tasks\EO_Projects wakeUpObject($row)
 * @method static \Bitrix\Tasks\EO_Projects_Collection wakeUpCollection($rows)
 */
class ProjectsTable extends Entity\DataManager
{
	private const DEFAULT_ORDER = 'asc';
	/**
	 * Returns DB table name for entity.
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_tasks_projects';
	}

	/**
	 * Returns entity map definition.
	 * @return array
	 */
	public static function getMap()
	{
		return array(
			'ID' => new Entity\IntegerField('ID', array(
				'primary' => true,
				'autocomplete' => true
			)),
			'ORDER_NEW_TASK' => new Entity\StringField('ORDER_NEW_TASK', array(
				'required' => true
			))
		);
	}

	/**
	 * Set project settings.
	 * @param int $id Project id.
	 * @param array $fields Settings array.
	 * @return void
	 */
	public static function set($id, $fields)
	{
		if (self::getById($id)->fetch())
		{
			self::update($id, $fields);
		}
		else
		{
			$fields['ID'] = $id;
			self::add($fields);
		}
	}

	/**
	 * Delete all rows after group delete.
	 * @param int $groupId Group id.
	 * @return void
	 */
	public static function onSocNetGroupDelete($groupId)
	{
		self::delete($groupId);
	}

	public static function getOrder(int $groupId): string
	{
		$query = static::query();
		$query
			->setSelect(['ID', 'ORDER_NEW_TASK'])
			->where('ID', $groupId);

		$order = $query->exec()->fetchObject()?->getOrderNewTask();

		return $order ?? static::DEFAULT_ORDER;
	}
}