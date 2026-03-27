<?php
namespace Bitrix\Dav\Internals;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Class DavConnectionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_DavConnection_Query query()
 * @method static EO_DavConnection_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_DavConnection_Result getById($id)
 * @method static EO_DavConnection_Result getList(array $parameters = [])
 * @method static EO_DavConnection_Entity getEntity()
 * @method static \Bitrix\Dav\Internals\EO_DavConnection createObject($setDefaultValues = true)
 * @method static \Bitrix\Dav\Internals\EO_DavConnection_Collection createCollection()
 * @method static \Bitrix\Dav\Internals\EO_DavConnection wakeUpObject($row)
 * @method static \Bitrix\Dav\Internals\EO_DavConnection_Collection wakeUpCollection($rows)
 */
class DavConnectionTable extends DataManager
{
	private const ENTITY_TYPE_USER = 'user';

	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_dav_connections';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new StringField('ENTITY_TYPE'))
				->configureRequired()
				->configureSize(32)
				->configureDefaultValue(self::ENTITY_TYPE_USER)
			,
			(new IntegerField('ENTITY_ID'))
				->configureRequired()
			,
			(new StringField('ACCOUNT_TYPE'))
				->configureRequired()
				->configureSize(32)
			,
			(new StringField('SYNC_TOKEN'))
				->configureSize(128)
				->configureNullable()
			,
			(new StringField('NAME'))
				->configureRequired()
				->configureSize(128)
			,
			(new StringField('SERVER_SCHEME'))
				->configureSize(5)
				->configureDefaultValue('http')
			,
			(new StringField('SERVER_HOST'))
				->configureSize(128)
			,
			(new IntegerField('SERVER_PORT'))
				->configureDefaultValue(80)
			,
			(new StringField('SERVER_USERNAME'))
				->configureSize(128)
				->configureNullable()
			,
			(new StringField('SERVER_PASSWORD'))
				->configureSize(128)
				->configureNullable()
			,
			(new StringField('SERVER_PATH'))
				->configureSize(128)
				->configureDefaultValue('/')
			,
			(new StringField('LAST_RESULT'))
				->configureSize(128)
				->configureNullable()
			,
			(new DatetimeField('CREATED'))
				->configureDefaultValue(static function () {
					return new DateTime();
				})
			,
			(new DatetimeField('MODIFIED'))
				->configureDefaultValue(static function () {
					return new DateTime();
				})
			,
			(new DatetimeField('SYNCHRONIZED'))
				->configureNullable()
			,
			(new BooleanField('IS_DELETED'))
				->configureRequired()
				->configureStorageValues('N', 'Y')
				->configureDefaultValue('N')
			,
			(new DatetimeField('NEXT_SYNC_TRY'))
				->configureDefaultValue(static function () {
					return new DateTime();
				})
			,
		];
	}
}
