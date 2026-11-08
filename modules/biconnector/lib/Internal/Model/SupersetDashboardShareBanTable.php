<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

/**
 * Class SupersetDashboardShareBanTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SupersetDashboardShareBan_Query query()
 * @method static EO_SupersetDashboardShareBan_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SupersetDashboardShareBan_Result getById($id)
 * @method static EO_SupersetDashboardShareBan_Result getList(array $parameters = [])
 * @method static EO_SupersetDashboardShareBan_Entity getEntity()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShareBan createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShareBan_Collection createCollection()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShareBan wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShareBan_Collection wakeUpCollection($rows)
 */
class SupersetDashboardShareBanTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_superset_dashboard_share_ban';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new Fields\IntegerField('DASHBOARD_ID'))
				->configureRequired(),
			(new Fields\IntegerField('CREATED_BY_ID'))
				->configureRequired(),
			(new Fields\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime()),
		];
	}
}
