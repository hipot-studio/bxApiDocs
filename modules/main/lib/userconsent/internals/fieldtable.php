<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */
namespace Bitrix\Main\UserConsent\Internals;

use Bitrix\Main\ORM\Data\DataManager;

/**
 * Class FieldTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Field_Query query()
 * @method static EO_Field_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Field_Result getById($id)
 * @method static EO_Field_Result getList(array $parameters = [])
 * @method static EO_Field_Entity getEntity()
 * @method static \Bitrix\Main\UserConsent\Internals\EO_Field createObject($setDefaultValues = true)
 * @method static \Bitrix\Main\UserConsent\Internals\EO_Field_Collection createCollection()
 * @method static \Bitrix\Main\UserConsent\Internals\EO_Field wakeUpObject($row)
 * @method static \Bitrix\Main\UserConsent\Internals\EO_Field_Collection wakeUpCollection($rows)
 */
class FieldTable extends DataManager
{
	private static array $consentFields = [];

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_consent_field';
	}

	/**
	 * Get map.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
			),
			'AGREEMENT_ID' => array(
				'data_type' => 'integer',
				'required' => true,
			),
			'CODE' => array(
				'data_type' => 'string',
				'required' => true,
			),
			'VALUE' => array(
				'data_type' => 'text',
				'required' => true,
			)
		);
	}

	/**
	 * Get user consent fields.
	 *
	 * @param integer $agreementId Agreement ID.
	 * @return array
	 */
	public static function getConsentFields($agreementId)
	{
		$agreementId = (int)$agreementId;

		if (isset(self::$consentFields[$agreementId]))
		{
			return self::$consentFields[$agreementId];
		}

		$fieldsDb = static::getList(array(
			'filter' => array(
				'=AGREEMENT_ID' => $agreementId
			)
		));
		self::$consentFields[$agreementId] = [];
		while ($field = $fieldsDb->fetch())
		{
			self::$consentFields[$agreementId][$field['CODE']] = $field['VALUE'];
		}

		return self::$consentFields[$agreementId];
	}

	/**
	 * Set user consent fields.
	 *
	 * @param integer $agreementId Agreement ID.
	 * @param array $fields Fields.
	 * @return void
	 */
	public static function setConsentFields($agreementId, array $fields)
	{
		$agreementId = (int)$agreementId;

		// remove old fields
		$deleteFieldsDb = static::getList(array(
			'select' => array('ID'),
			'filter' => array(
				'=AGREEMENT_ID' => $agreementId
			)
		));
		while ($deleteField = $deleteFieldsDb->fetch())
		{
			static::delete($deleteField['ID']);
		}

		// add new fields
		foreach ($fields as $code => $value)
		{
			$result = static::add(array(
				'AGREEMENT_ID' => $agreementId,
				'CODE' => $code,
				'VALUE' => $value,
			));
			$result->isSuccess();
		}

		unset(self::$consentFields[$agreementId]);
	}
}
