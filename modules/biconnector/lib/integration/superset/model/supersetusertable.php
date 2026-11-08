<?php
namespace Bitrix\BIConnector\Integration\Superset\Model;

use Bitrix\BIConnector\Integration\Superset\SupersetUserLogin;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Fields\Validators\RegExpValidator;

/**
 * Class SupersetUserTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> USER_ID int mandatory
 * <li> CLIENT_ID string(32) mandatory
 * <li> PERMISSION_HASH string(32) mandatory
 * <li> UPDATED bool mandatory
 * <li> LOGIN string(64) optional
 * </ul>
 *
 * @package Bitrix\BIConnector\Integration\Superset\Model
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SupersetUser_Query query()
 * @method static EO_SupersetUser_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SupersetUser_Result getById($id)
 * @method static EO_SupersetUser_Result getList(array $parameters = [])
 * @method static EO_SupersetUser_Entity getEntity()
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetUser createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetUser_Collection createCollection()
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetUser wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetUser_Collection wakeUpCollection($rows)
 */

class SupersetUserTable extends DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_biconnector_superset_user';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			new IntegerField(
				'ID',
				[
					'primary' => true,
					'autocomplete' => true,
					'title' => Loc::getMessage('SUPERSET_USER_ENTITY_ID_FIELD'),
				]
			),
			new IntegerField(
				'USER_ID',
				[
					'required' => true,
					'title' => Loc::getMessage('SUPERSET_USER_ENTITY_USER_ID_FIELD'),
				]
			),
			new StringField(
				'CLIENT_ID',
				[
					'required' => true,
					'validation' => fn() => [new LengthValidator(null, 32)],
					'title' => Loc::getMessage('SUPERSET_USER_ENTITY_CLIENT_ID_FIELD'),
				]
			),
			new StringField(
				'PERMISSION_HASH',
				[
					'required' => false,
					'validation' => fn() => [new LengthValidator(null, 32)],
					'title' => Loc::getMessage('SUPERSET_USER_ENTITY_PERMISSION_HASH_FIELD'),
				]
			),
			new BooleanField(
				'UPDATED',
				[
					'required' => false,
					'values' => ['N', 'Y'],
					'default' => 'Y',
					'title' => Loc::getMessage('SUPERSET_USER_ENTITY_BPDATED_FIELD'),
				]
			),
			new StringField(
				'LOGIN',
				[
					'required' => false,
					// an empty value stays valid: rows predating the column carry NULL until the backfill fills them.
					// The derivation hides this validator from every ordinary write, but it is still the only guard
					// of addMulti()/updateMulti() with $ignoreEvents, where checkFields() runs and the handlers do not.
					'validation' => fn() => [
						new LengthValidator(null, 64),
						new RegExpValidator('/^([0-9a-f]{16})?$/D'),
					],
				]
			),
		];
	}

	/**
	 * Derives LOGIN from USER_ID, so that any write path gets it without asking the caller.
	 * A login coming from the outside is never stored: with no USER_ID to derive from the field is
	 * dropped, and the row is written without a login.
	 *
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onBeforeAdd(Event $event): EventResult
	{
		$result = new EventResult();

		$fields = $event->getParameter('fields');
		$userId = (int)($fields['USER_ID'] ?? 0);
		if ($userId > 0)
		{
			$result->modifyFields([
				'LOGIN' => SupersetUserLogin::getLogin($userId),
			]);
		}
		else
		{
			$result->unsetFields(['LOGIN']);
		}

		return $result;
	}

	/**
	 * Derives LOGIN on update too, but only for a caller that passes the field. The hot path of
	 * permission sync never does - updatePermissionHash() and updateUpdated() write their own field -
	 * so for it the handler is one array lookup and no query. The backfill of the links written before
	 * the column does pass LOGIN, and its row gets the login derived from that row's own USER_ID, so a
	 * login of another employee cannot be stored through this path either.
	 *
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();

		$fields = $event->getParameter('fields');
		if (!array_key_exists('LOGIN', $fields))
		{
			return $result;
		}

		$primary = $event->getParameter('id');
		$row = self::getRow([
			'select' => ['USER_ID'],
			'filter' => ['=ID' => (int)($primary['ID'] ?? 0)],
		]);
		$userId = (int)($row['USER_ID'] ?? 0);
		if ($userId > 0)
		{
			$result->modifyFields([
				'LOGIN' => SupersetUserLogin::getLogin($userId),
			]);
		}
		else
		{
			$result->unsetFields(['LOGIN']);
		}

		return $result;
	}

	public static function addClientId(int $userId, string $clientId): AddResult
	{
		return self::add([
			'USER_ID' => $userId,
			'CLIENT_ID' => $clientId,
		]);
	}

	/**
	 * @param int $userId
	 * @param string $permissionHash
	 * @return UpdateResult
	 * @throws ObjectNotFoundException
	 */
	public static function updatePermissionHash(int $userId, string $permissionHash): UpdateResult
	{
		$result = self::getRow([
			'select' => ['ID'],
			'filter' => ['=USER_ID' => $userId],
		]);

		if (!$result)
		{
			throw new ObjectNotFoundException("User with USER_ID: {$userId} not found");
		}

		return self::update($result['ID'], ['PERMISSION_HASH' => $permissionHash]);
	}

	/**
	 * @param int $userId
	 * @param bool $updated
	 * @return UpdateResult
	 * @throws ObjectNotFoundException
	 */
	public static function updateUpdated(int $userId, bool $updated): UpdateResult
	{
		$result = self::getRow([
			'select' => ['ID'],
			'filter' => ['=USER_ID' => $userId],
		]);

		if (!$result)
		{
			throw new ObjectNotFoundException("User with USER_ID: {$userId} not found");
		}

		return self::update($result['ID'], ['UPDATED' => $updated]);
	}
}
