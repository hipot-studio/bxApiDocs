<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Class SupersetDashboardInfoTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SupersetDashboardInfo_Query query()
 * @method static EO_SupersetDashboardInfo_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SupersetDashboardInfo_Result getById($id)
 * @method static EO_SupersetDashboardInfo_Result getList(array $parameters = [])
 * @method static EO_SupersetDashboardInfo_Entity getEntity()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfo createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfo_Collection createCollection()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfo wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfo_Collection wakeUpCollection($rows)
 */
class SupersetDashboardInfoTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_superset_dashboard_info';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('DASHBOARD_ID'))
				->configureRequired(),
			(new IntegerField('PUBLISHED_BY_ID'))
				->configureNullable(),
			(new DatetimeField('PUBLISHED_DATE')),
			(new IntegerField('UPDATED_BY_ID'))
				->configureNullable(),
			(new DatetimeField('UPDATED_DATE')),
			(new TextField('DESCRIPTION'))
				->configureNullable(),
			(new IntegerField('IMAGE_ID'))
				->configureNullable(),
		];
	}

	public static function onAfterDelete(Event $event): void
	{
		$primary = $event->getParameter('id');
		$dashboardInfoId = (int)($primary['ID'] ?? 0);
		if ($dashboardInfoId > 0)
		{
			SupersetDashboardInfoGalleryTable::deleteByFilter(['=DASHBOARD_INFO_ID' => $dashboardInfoId]);
		}
	}

	protected static function onBeforeDeleteByFilter(string $where): void
	{
		$dashboardInfoIds = static::getInfoIdsByWhere($where);
		if (!empty($dashboardInfoIds))
		{
			SupersetDashboardInfoGalleryTable::deleteByFilter(['=DASHBOARD_INFO_ID' => $dashboardInfoIds]);
		}
	}

	private static function getInfoIdsByWhere(string $where): array
	{
		$connection = static::getEntity()->getConnection();
		$tableName = static::getTableName();
		$result = $connection->query("SELECT ID FROM {$tableName} {$where}");

		$dashboardInfoIds = [];

		while ($row = $result->fetch())
		{
			$dashboardInfoId = (int)($row['ID'] ?? 0);
			if ($dashboardInfoId > 0)
			{
				$dashboardInfoIds[] = $dashboardInfoId;
			}
		}

		return array_values(array_unique($dashboardInfoIds));
	}
}
