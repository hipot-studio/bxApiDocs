<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class SupersetDashboardInfoGalleryTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SupersetDashboardInfoGallery_Query query()
 * @method static EO_SupersetDashboardInfoGallery_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SupersetDashboardInfoGallery_Result getById($id)
 * @method static EO_SupersetDashboardInfoGallery_Result getList(array $parameters = [])
 * @method static EO_SupersetDashboardInfoGallery_Entity getEntity()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfoGallery createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfoGallery_Collection createCollection()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfoGallery wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfoGallery_Collection wakeUpCollection($rows)
 */
class SupersetDashboardInfoGalleryTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_superset_dashboard_info_gallery';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('DASHBOARD_INFO_ID'))
				->configureRequired(),
			(new IntegerField('IMAGE_ID'))
				->configureRequired(),
			(new IntegerField('SORT'))
				->configureDefaultValue(500),
		];
	}
}
