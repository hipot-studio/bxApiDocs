<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class SupersetDashboardViewTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SupersetDashboardView_Query query()
 * @method static EO_SupersetDashboardView_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SupersetDashboardView_Result getById($id)
 * @method static EO_SupersetDashboardView_Result getList(array $parameters = [])
 * @method static EO_SupersetDashboardView_Entity getEntity()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardView createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardView_Collection createCollection()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardView wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardView_Collection wakeUpCollection($rows)
 */
class SupersetDashboardViewTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_superset_dashboard_view';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('DASHBOARD_ID'))
				->configureRequired(),
			(new IntegerField('USER_ID'))
				->configureRequired(),
			(new DatetimeField('VIEWED_AT'))
				->configureRequired(),
		];
	}
}
