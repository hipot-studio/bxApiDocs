<?php
namespace Bitrix\Disk\Internals;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;

/**
 * Class AttachedObjectTable
 * 
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> OBJECT_ID int mandatory
 * <li> VERSION_ID int optional
 * <li> IS_EDITABLE int optional
 * <li> ALLOW_EDIT int optional
 * <li> ALLOW_AUTO_COMMENT int optional
 * <li> MODULE_ID string(32) optional
 * <li> ENTITY_TYPE string(100) optional
 * <li> ENTITY_ID int optional
 * <li> CREATE_TIME datetime mandatory
 * <li> CREATED_BY int
 * <li> OWNER_PARAMETERS string(512) optional
 * </ul>
 *
 * @package Bitrix\Disk
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AttachedObject_Query query()
 * @method static EO_AttachedObject_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AttachedObject_Result getById($id)
 * @method static EO_AttachedObject_Result getList(array $parameters = [])
 * @method static EO_AttachedObject_Entity getEntity()
 * @method static \Bitrix\Disk\Internals\EO_AttachedObject createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internals\EO_AttachedObject_Collection createCollection()
 * @method static \Bitrix\Disk\Internals\EO_AttachedObject wakeUpObject($row)
 * @method static \Bitrix\Disk\Internals\EO_AttachedObject_Collection wakeUpCollection($rows)
 */

final class AttachedObjectTable extends DataManager
{
	public const ERROR_OWNER_PARAMETERS_IMMUTABLE = 'DISK_AO_OWNER_PARAMETERS_IMMUTABLE';

	public static function getTableName()
	{
		return 'b_disk_attached_object';
	}

	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			),
			'OBJECT_ID' => array(
				'data_type' => 'integer',
				'required' => true,
			),
			'OBJECT' => array(
				'data_type' => 'Bitrix\Disk\Internals\FileTable',
				'reference' => array(
					'=this.OBJECT_ID' => 'ref.ID'
				),
				'join_type' => 'INNER',
			),
			'VERSION_ID' => array(
				'data_type' => 'integer',
			),
			'VERSION' => array(
				'data_type' => 'Bitrix\Disk\Internals\VersionTable',
				'reference' => array(
					'=this.VERSION_ID' => 'ref.ID'
				),
			),
			'IS_EDITABLE' => array(
				'data_type' => 'enum',
				'values' => array(0, 1, 2),
				'default_value' => 0,
			),
			'ALLOW_EDIT' => array(
				'data_type' => 'enum',
				'values' => array(0, 1),
				'default_value' => 0,
			),
			'ALLOW_AUTO_COMMENT' => array(
				'data_type' => 'enum',
				'values' => array(0, 1),
				'default_value' => 1,
			),
			'MODULE_ID' => array(
				'data_type' => 'string',
				'required' => true,
				'validation' => array(__CLASS__, 'validateModule'),
			),
			'ENTITY_TYPE' => array(
				'data_type' => 'string',
				'required' => true,
				'validation' => array(__CLASS__, 'validateEntityType'),
			),
			'ENTITY_ID' => array(
				'data_type' => 'integer',
				'required' => true,
			),
			'CREATE_TIME' => array(
				'data_type' => 'datetime',
				'required' => true,
				'default_value' => function() {
					return new DateTime();
				},
			),
			'CREATED_BY' => array(
				'data_type' => 'integer',
			),
			'CREATE_USER' => array(
				'data_type' => 'Bitrix\Main\UserTable',
				'reference' => array(
					'=this.CREATED_BY' => 'ref.ID'
				),
			),
			'OWNER_PARAMETERS' => [
				'data_type' => 'string',
			],
		);
	}

	public static function validateModule()
	{
		return array(
			new Entity\Validator\Length(1, 32),
		);
	}

	public static function validateEntityType()
	{
		return array(
			new Entity\Validator\Length(1, 100),
		);
	}

	/**
	 * Refuses any change of the owner parameters: they are written only by the INSERT that creates the attachment.
	 * @param Entity\Event $event Event.
	 * @return Entity\EventResult
	 */
	public static function onBeforeUpdate(Entity\Event $event)
	{
		$result = new Entity\EventResult();
		/** @var array $fields */
		$fields = $event->getParameter('fields');
		if (array_key_exists('OWNER_PARAMETERS', $fields))
		{
			$result->addError(new Entity\EntityError(
				'Owner parameters of an attached object cannot be changed after creation.',
				self::ERROR_OWNER_PARAMETERS_IMMUTABLE
			));
		}

		return $result;
	}

	/**
	 * Updates rows by filter (simple format).
	 * Filter support only column = value. Only =.
	 * @param array $fields Fields.
	 * @param array $filter Filter.
	 * @throws ArgumentException When the fields contain the owner parameters.
	 */
	public static function updateBatch(array $fields, array $filter)
	{
		// raw UPDATE without ORM events: onBeforeUpdate does not guard it
		if (array_key_exists('OWNER_PARAMETERS', $fields))
		{
			throw new ArgumentException(
				'Owner parameters of an attached object cannot be changed after creation.',
				'fields'
			);
		}

		parent::updateBatch($fields, $filter);
	}
}
