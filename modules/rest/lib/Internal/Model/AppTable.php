<?php

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DateField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Rest\HandlerHelper;
use Bitrix\Rest\RestException;

/**
 * Class AppTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_App_Query query()
 * @method static EO_App_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_App_Result getById($id)
 * @method static EO_App_Result getList(array $parameters = [])
 * @method static EO_App_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_App createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_App_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_App wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_App_Collection wakeUpCollection($rows)
 */
class AppTable extends DataManager
{
	protected static $licenseLang = null;

	public static function getTableName(): string
	{
		return 'b_rest_app';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('CLIENT_ID'))
				->configureRequired()
				->configureSize(128)
				->configureUnique(),

			(new StringField('CODE'))
				->configureRequired()
				->configureSize(128)
				->configureUnique(),

			(new BooleanField('ACTIVE'))
				->configureValues('N', 'Y'),

			(new BooleanField('INSTALLED'))
				->configureValues('N', 'Y'),

			(new StringField('URL'))
				->configureSize(1000),

			(new StringField('URL_DEMO'))
				->configureSize(1000),

			(new StringField('URL_INSTALL'))
				->configureSize(1000)
				->addValidator(static function ($value, $primary, array $row) {
					if (empty($value))
					{
						return true;
					}

					try
					{
						if (HandlerHelper::checkCallback($value, $row, false))
						{
							return true;
						}
					}
					catch (RestException $e)
					{
					}

					return 'Incorrect link for the initial installation.';
				}),

			(new StringField('URL_SETTINGS'))
				->configureSize(1000)
			,

			(new StringField('VERSION'))
				->configureSize(4),

			(new StringField('SCOPE'))
				->configureRequired()
				->configureSize(2000),

			(new EnumField('STATUS'))
				->configureRequired()
				->configureValues(array_column(AppStatus::cases(), 'value')),

			(new DatetimeField('DATE_CREATE')),
			(new DatetimeField('DATE_INSTALL')),
			(new DateField('DATE_FINISH')),

			(new BooleanField('IS_TRIALED'))
				->configureValues('N', 'Y'),

			(new StringField('SHARED_KEY'))
				->configureSize(32),

			(new StringField('CLIENT_SECRET'))
				->configureSize(100),

			(new StringField('APP_NAME'))
				->configureSize(1000),

			(new StringField('ACCESS'))
				->configureSize(2000),

			(new StringField('APPLICATION_TOKEN'))
				->configureSize(50),

			(new BooleanField('MOBILE'))
				->configureValues('N', 'Y'),

			(new BooleanField('USER_INSTALL'))
				->configureValues('N', 'Y'),
		];
	}
}
