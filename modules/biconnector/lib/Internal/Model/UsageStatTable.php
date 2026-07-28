<?php

namespace Bitrix\BIConnector\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class UsageStatTable
 *
 * Stores statistics of BI queries (b_biconnector_log).
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> TIMESTAMP_X datetime mandatory
 * <li> KEY_ID int mandatory
 * <li> SERVICE_ID string(150) mandatory
 * <li> SOURCE_ID string(150) mandatory
 * <li> FIELDS text optional
 * <li> FILTERS text optional
 * <li> ROW_NUM int optional
 * <li> DATA_SIZE int optional
 * <li> REAL_TIME double optional
 * <li> IS_OVER_LIMIT bool optional
 * <li> INPUT text optional
 * <li> REQUEST_METHOD string(15) optional
 * <li> REQUEST_URI string(2000) optional
 * <li> SOURCE string(16) optional
 * <li> DASHBOARD_ID int optional
 * <li> DASHBOARD_NAME string(1024) optional
 * <li> CHART_ID string(255) optional
 * <li> CHART_NAME string(1024) optional
 * <li> DATASET_ID int optional
 * <li> DATASET_NAME string(1024) optional
 * <li> KEY reference to {@link \Bitrix\BIConnector\KeyTable}
 * </ul>
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_UsageStat_Query query()
 * @method static EO_UsageStat_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_UsageStat_Result getById($id)
 * @method static EO_UsageStat_Result getList(array $parameters = [])
 * @method static EO_UsageStat_Entity getEntity()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_UsageStat createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_UsageStat_Collection createCollection()
 * @method static \Bitrix\BIConnector\Internal\Model\EO_UsageStat wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Internal\Model\EO_UsageStat_Collection wakeUpCollection($rows)
 */
class UsageStatTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_log';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new DatetimeField('TIMESTAMP_X'))
				->configureRequired()
			,
			(new IntegerField('KEY_ID'))
				->configureRequired()
			,
			(new StringField('SERVICE_ID'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 150))
			,
			(new StringField('SOURCE_ID'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 150))
			,
			new StringField('FIELDS'),
			new StringField('FILTERS'),
			new TextField('INPUT'),
			(new StringField('REQUEST_METHOD'))
				->addValidator(new LengthValidator(null, 15))
			,
			(new StringField('REQUEST_URI'))
				->addValidator(new LengthValidator(null, 2000))
			,
			new IntegerField('ROW_NUM'),
			new IntegerField('DATA_SIZE'),
			new FloatField('REAL_TIME'),
			(new BooleanField('IS_OVER_LIMIT'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
			,
			(new StringField('SOURCE'))
				->addValidator(new LengthValidator(null, 255))
			,
			new IntegerField('EXTERNAL_DASHBOARD_ID'),
			(new StringField('EXTERNAL_DASHBOARD_NAME'))
				->addValidator(new LengthValidator(null, 500))
			,
			(new StringField('EXTERNAL_CHART_ID'))
				->addValidator(new LengthValidator(null, 255))
			,
			(new StringField('EXTERNAL_CHART_NAME'))
				->addValidator(new LengthValidator(null, 250))
			,
			new IntegerField('EXTERNAL_DATASET_ID'),
			(new StringField('EXTERNAL_DATASET_NAME'))
				->addValidator(new LengthValidator(null, 250))
			,
			new Reference(
				'KEY',
				\Bitrix\BIConnector\KeyTable::class,
				['=this.KEY_ID' => 'ref.ID'],
				['join_type' => 'LEFT']
			),
		];
	}
}
