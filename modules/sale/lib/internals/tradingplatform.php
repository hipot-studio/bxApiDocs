<?php
namespace Bitrix\Sale;

use Bitrix\Main\Entity;

/**
 * Class TradingPlatformTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> CODE string(20) mandatory
 * <li> NAME string(50) mandatory
 * <li> DESCRIPTION string(255) mandatory
 * <li> SETTINGS string mandatory
 * </ul>
 *
 * @package Bitrix\Sale
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TradingPlatform_Query query()
 * @method static EO_TradingPlatform_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TradingPlatform_Result getById($id)
 * @method static EO_TradingPlatform_Result getList(array $parameters = [])
 * @method static EO_TradingPlatform_Entity getEntity()
 * @method static \Bitrix\Sale\EO_TradingPlatform createObject($setDefaultValues = true)
 * @method static \Bitrix\Sale\EO_TradingPlatform_Collection createCollection()
 * @method static \Bitrix\Sale\EO_TradingPlatform wakeUpObject($row)
 * @method static \Bitrix\Sale\EO_TradingPlatform_Collection wakeUpCollection($rows)
 */

class TradingPlatformTable extends Entity\DataManager
{
	public static function getTableName()
	{
		return 'b_sale_tp';
	}

	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			),
			'CODE' => array(
				'required' => true,
				'data_type' => 'string',
				'validation' => array(__CLASS__, 'validateCode'),
			),
			'ACTIVE' => array(
				'required' => true,
				'data_type' => 'string',
				'validation' => array(__CLASS__, 'validateActive'),
			),
			'NAME' => array(
				'data_type' => 'string',
				'validation' => array(__CLASS__, 'validateName'),
			),
			'DESCRIPTION' => array(
				'data_type' => 'string',
				'validation' => array(__CLASS__, 'validateDescription'),
			),
			'SETTINGS' => array(
				'data_type' => 'text',
				'serialized' => true,
			),
			'CATALOG_SECTION_TAB_CLASS_NAME' => array(
				'data_type' => 'string',
				'validation' => array(__CLASS__, 'validateCatalogSectionTabClassName'),
			),
			'CLASS' => array(
				'data_type' => 'string',
				'validation' => array(__CLASS__, 'validateClass'),
			),
			'XML_ID' => array(
				'data_type' => 'string',
				'title' => 'XML_ID',
			)
		);
	}
	public static function validateCode()
	{
		return array(
			new Entity\Validator\Length(null, 20),
		);
	}
	public static function validateActive()
	{
		return array(
			new Entity\Validator\Length(null, 1),
		);
	}
	public static function validateName()
	{
		return array(
			new Entity\Validator\Length(null, 500),
		);
	}
	public static function validateDescription()
	{
		return array(
			new Entity\Validator\Length(null, 255),
		);
	}
	public static function validateCatalogSectionTabClassName()
	{
		return array(
			new Entity\Validator\Length(null, 255),
		);
	}
	public static function validateClass()
	{
		return array(
			new Entity\Validator\Length(null, 255),
		);
	}
}
