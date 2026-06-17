<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

/**
 * Class SupersetDashboardShareTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SupersetDashboardShare_Query query()
 * @method static EO_SupersetDashboardShare_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SupersetDashboardShare_Result getById($id)
 * @method static EO_SupersetDashboardShare_Result getList(array $parameters = [])
 * @method static EO_SupersetDashboardShare_Entity getEntity()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShare createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShare_Collection createCollection()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShare wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShare_Collection wakeUpCollection($rows)
 */
class SupersetDashboardShareTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_superset_dashboard_share';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new Fields\IntegerField('DASHBOARD_ID'))
				->configureRequired(),
			(new Fields\StringField('TOKEN'))
				->configureRequired()
				->configureSize(64),
			(new Fields\CryptoField('PASSWORD', [
				'crypto_enabled' => static::cryptoEnabled('PASSWORD'),
			]))
				->configureRequired(),
			(new Fields\DatetimeField('DATE_EXPIRE'))
				->configureRequired(),
			(new Fields\EnumField('ACTIVE'))
				->configureRequired()
				->configureValues(['N', 'Y'])
				->configureDefaultValue('Y'),
			(new Fields\IntegerField('CREATED_BY_ID'))
				->configureRequired(),
			(new Fields\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime()),
			(new Fields\DatetimeField('DATE_MODIFY'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime()),
			(new Fields\TextField('EXTERNAL_FILTER_VALUES'))
				->configureNullable(),
			(new Fields\TextField('URL_PARAMETER_VALUES'))
				->configureNullable(),
			(new Fields\IntegerField('LOGIN_ATTEMPTS'))
				->configureRequired()
				->configureDefaultValue(0),
			(new Fields\DatetimeField('LOGIN_LOCKED_TILL'))
				->configureNullable(),
			(new ReferenceField(
				'DASHBOARD',
				SupersetDashboardTable::class,
				Join::on('this.DASHBOARD_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),
		];
	}

	public static function generateToken(): string
	{
		return bin2hex(random_bytes(32));
	}
}
