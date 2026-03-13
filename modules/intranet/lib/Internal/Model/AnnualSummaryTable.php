<?php

namespace Bitrix\Intranet\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Class AnnualSummaryTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AnnualSummary_Query query()
 * @method static EO_AnnualSummary_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AnnualSummary_Result getById($id)
 * @method static EO_AnnualSummary_Result getList(array $parameters = [])
 * @method static EO_AnnualSummary_Entity getEntity()
 * @method static \Bitrix\Intranet\Internal\Model\EO_AnnualSummary createObject($setDefaultValues = true)
 * @method static \Bitrix\Intranet\Internal\Model\EO_AnnualSummary_Collection createCollection()
 * @method static \Bitrix\Intranet\Internal\Model\EO_AnnualSummary wakeUpObject($row)
 * @method static \Bitrix\Intranet\Internal\Model\EO_AnnualSummary_Collection wakeUpCollection($rows)
 */
class AnnualSummaryTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_intranet_user_annual_summary';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('USER_ID'))
				->configureRequired(),
			(new StringField('NAME'))
				->configureRequired(),
			(new TextField('VALUE'))
				->configureNullable(),
		];
	}
}