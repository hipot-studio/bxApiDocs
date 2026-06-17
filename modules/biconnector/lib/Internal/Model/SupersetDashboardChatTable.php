<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class SupersetDashboardChatTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SupersetDashboardChat_Query query()
 * @method static EO_SupersetDashboardChat_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SupersetDashboardChat_Result getById($id)
 * @method static EO_SupersetDashboardChat_Result getList(array $parameters = [])
 * @method static EO_SupersetDashboardChat_Entity getEntity()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardChat createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardChat_Collection createCollection()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardChat wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardChat_Collection wakeUpCollection($rows)
 */
class SupersetDashboardChatTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_superset_dashboard_chat';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('DASHBOARD_ID'))
				->configureRequired(),
			(new IntegerField('CHAT_ID'))
				->configureRequired(),
			(new IntegerField('CREATED_BY_ID'))
				->configureRequired(),
			(new DatetimeField('DATE_CREATE'))
				->configureRequired(),
		];
	}
}
