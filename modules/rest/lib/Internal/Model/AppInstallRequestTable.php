<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequestStatus;

/**
 * Class AppInstallRequestTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AppInstallRequest_Query query()
 * @method static EO_AppInstallRequest_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AppInstallRequest_Result getById($id)
 * @method static EO_AppInstallRequest_Result getList(array $parameters = [])
 * @method static EO_AppInstallRequest_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppInstallRequest createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppInstallRequest_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppInstallRequest wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppInstallRequest_Collection wakeUpCollection($rows)
 */
class AppInstallRequestTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_rest_app_install_request';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('APP_CODE'))
				->configureRequired()
				->configureSize(128),

			(new StringField('APP_VERSION'))
				->configureRequired()
				->configureSize(4)
				->configureDefaultValue('1'),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new StringField('CHECK_HASH'))
				->configureNullable()
				->configureSize(255),

			(new StringField('INSTALL_HASH'))
				->configureNullable()
				->configureSize(255),

			(new StringField('APP_NAME'))
				->configureNullable()
				->configureSize(255),

			(new StringField('APP_ICON_URL'))
				->configureNullable()
				->configureSize(1000),

			(new StringField('STATUS'))
				->configureRequired()
				->configureSize(16)
				->configureDefaultValue(AppInstallRequestStatus::Pending->value),

			(new IntegerField('RESOLVED_BY_ID'))
				->configureNullable(),

			(new DatetimeField('DATE_CREATE'))
				->configureDefaultValueNow(),

			(new DatetimeField('DATE_RESOLVE'))
				->configureNullable(),
		];
	}
}
