<?php
namespace Bitrix\Bizproc\Automation\Trigger\Entity;

use Bitrix\Main;

/**
 * Class TriggerTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Trigger_Query query()
 * @method static EO_Trigger_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Trigger_Result getById($id)
 * @method static EO_Trigger_Result getList(array $parameters = [])
 * @method static EO_Trigger_Entity getEntity()
 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection createCollection()
 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\TriggerObject wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Automation\Trigger\Entity\EO_Trigger_Collection wakeUpCollection($rows)
 */
class TriggerTable extends Main\Entity\DataManager
{
	/**
	 * Get table name.
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_bp_automation_trigger';
	}

	public static function getObjectClass()
	{
		return TriggerObject::class;
	}

	/**
	 * Get table fields map.
	 * @return array
	 */
	public static function getMap()
	{
		return [
			'ID' => [
				'primary' => true,
				'data_type' => 'integer',
				'autocomplete' => true,
			],
			'NAME' => [
				'data_type' => 'string',
				'save_data_modification' => [__CLASS__, 'getNameSaveModifiers'],
			],
			'CODE' => ['data_type' => 'string'],

			'MODULE_ID' => ['data_type' => 'string'],
			'ENTITY' => ['data_type' => 'string'],
			'DOCUMENT_TYPE' => ['data_type' => 'string'],

			'DOCUMENT_STATUS' => ['data_type' => 'string'],

			'APPLY_RULES' => [
				'data_type' => 'string',
				'serialized' => true,
			],
		];
	}

	/**
	 * Save modifiers for the NAME field.
	 *
	 * NAME is not a meaningful field for a trigger (it is identified by CODE/DOCUMENT_STATUS),
	 * so an empty string is the valid default. The modifier normalizes the value to a string
	 * to prevent a null from reaching the NOT NULL column (both on add and on update).
	 *
	 * @return array Array of callbacks.
	 */
	public static function getNameSaveModifiers()
	{
		return [
			static fn($value) => (string)$value,
		];
	}
}
