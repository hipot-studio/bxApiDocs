<?php

namespace Bitrix\Security\Mfa;

use Bitrix\Main\Type;
use Bitrix\Main\Security\Random;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentTypeException;

/**
 * Class RecoveryCodesTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RecoveryCodes_Query query()
 * @method static EO_RecoveryCodes_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RecoveryCodes_Result getById($id)
 * @method static EO_RecoveryCodes_Result getList(array $parameters = [])
 * @method static EO_RecoveryCodes_Entity getEntity()
 * @method static \Bitrix\Security\Mfa\EO_RecoveryCodes createObject($setDefaultValues = true)
 * @method static \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection createCollection()
 * @method static \Bitrix\Security\Mfa\EO_RecoveryCodes wakeUpObject($row)
 * @method static \Bitrix\Security\Mfa\EO_RecoveryCodes_Collection wakeUpCollection($rows)
 */
class RecoveryCodesTable extends DataManager
{
	const CODES_PER_USER = 10;
	const CODE_PATTERN = '#^[a-z0-9]{4}-[a-z0-9]{4}$#D';

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_sec_recovery_codes';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return array(
			new Fields\IntegerField('ID', array(
				'primary' => true,
				'autocomplete' => true
			)),
			new Fields\IntegerField('USER_ID', array(
				'required' => true
			)),
			new Fields\StringField('CODE', array(
				'required' => true,
				'format' => static::CODE_PATTERN
			)),
			new Fields\BooleanField('USED', array(
				'values' => array('Y', 'N'),
				'default' => 'N'
			)),
			new Fields\DatetimeField('USING_DATE'),
			new Fields\StringField('USING_IP'),
			new Fields\Relations\Reference(
				'USER',
				'Bitrix\Main\User',
				array('=this.USER_ID' => 'ref.ID'),
				array('join_type' => 'INNER')
			),
		);
	}

	/**
	 * Clear all saved recovery codes for provided user
	 *
	 * @param int $userId Needed user id.
	 * @return bool Returns true if successful
	 * @throws ArgumentTypeException
	 */
	public static function clearByUser($userId)
	{
		$userId = (int) $userId;
		if ($userId <= 0)
			throw new ArgumentTypeException('userId', 'positive integer');

		$codes = static::getList(array(
			'select' => array('ID'),
			'filter' => array('=USER_ID' => $userId)
		));

		while (($code = $codes->fetch()))
		{
			static::delete($code['ID']);
		}

		return true;
	}

	/**
	 * Generate new recovery codes for provided user
	 * Previously generated codes will be removed
	 *
	 * @param int $userId Needed user id.
	 * @return bool Returns true if successful
	 * @throws ArgumentTypeException
	 */
	public static function regenerateCodes($userId)
	{
		$userId = (int) $userId;
		if ($userId <= 0)
			throw new ArgumentTypeException('userId', 'positive integer');

		static::clearByUser($userId);

		$randomVector = Random::getString(static::CODES_PER_USER * 8);
		$randomVector = str_split($randomVector, 4);
		for ($i = 0; $i < static::CODES_PER_USER; $i++)
		{
			$code = array(
				'USER_ID' => $userId,
				'USED' => 'N',
				'CODE' => sprintf('%s-%s', $randomVector[$i * 2], $randomVector[($i * 2) + 1])
			);

			static::add($code);
		}

		return true;
	}

	/**
	 * Use recovery code for user
	 *
	 * @param int $userId Needed user id.
	 * @param string $searchCode Recovery code in accepted format (see RecoveryCodesTable::CODE_PATTERN).
	 * @return bool Returns true if successful
	 * @throws ArgumentTypeException
	 */
	public static function useCode($userId, $searchCode)
	{
		$userId = (int) $userId;
		if ($userId <= 0)
			throw new ArgumentTypeException('userId', 'positive integer');

		if (!preg_match(static::CODE_PATTERN, $searchCode))
			throw new ArgumentTypeException('searchCode', sprintf('string, check pattern "%s"', static::CODE_PATTERN));

		$codes = static::getList(array(
			'select' => array('ID', 'CODE'),
			'filter' => array('=USER_ID' => $userId, '=USED' => 'N'),
		));

		$found = false;
		while (($code = $codes->fetch()))
		{
			if($code['CODE'] === $searchCode)
			{
				static::update($code['ID'], array(
					'USED' => 'Y',
					'USING_DATE' => new Type\DateTime,
					'USING_IP' => Application::getInstance()->getContext()->getRequest()->getRemoteAddress()
				));
				$found = true;
				break;
			}
		}

		return $found;
	}
}
