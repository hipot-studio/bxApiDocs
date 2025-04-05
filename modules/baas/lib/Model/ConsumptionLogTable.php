<?php

namespace Bitrix\Baas\Model;

use Bitrix\Main\ORM;

/**
 * Class ConsumptionLogTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ConsumptionLog_Query query()
 * @method static EO_ConsumptionLog_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ConsumptionLog_Result getById($id)
 * @method static EO_ConsumptionLog_Result getList(array $parameters = [])
 * @method static EO_ConsumptionLog_Entity getEntity()
 * @method static \Bitrix\Baas\Model\EO_ConsumptionLog createObject($setDefaultValues = true)
 * @method static \Bitrix\Baas\Model\EO_ConsumptionLog_Collection createCollection()
 * @method static \Bitrix\Baas\Model\EO_ConsumptionLog wakeUpObject($row)
 * @method static \Bitrix\Baas\Model\EO_ConsumptionLog_Collection wakeUpCollection($rows)
 */
class ConsumptionLogTable extends ORM\Data\DataManager
{
	use Traits\UpdateBatch;

	public static function getTableName(): string
	{
		return 'b_baas_consumption_log';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configureTitle('ID')
				->configureAutocomplete()
				->configurePrimary()
			,
			(new ORM\Fields\StringField('SERVICE_CODE'))
				->configureTitle('Service string id')
				->configureRequired()
			,
			(new ORM\Fields\StringField('PURCHASED_PACKAGE_CODE'))
				->configureTitle('Purchase string id')
			,
			(new ORM\Fields\IntegerField('VALUE'))
				->configureTitle('Consumed or restored value')
				->configureRequired()
			,
			(new ORM\Fields\DatetimeField('TIMESTAMP_USE'))
				->configureTitle('Date of consumption')
			,
			//region Fields for bitrix24
			(new ORM\Fields\DatetimeField('SYNCHRONIZATION_DATE'))
				->configureTitle('Date of synchronization')
			,
			new ORM\Fields\IntegerField('SYNCHRONIZATION_ID'),
			//endregion
			//region Fields for migration to baas-controller
			(new ORM\Fields\DatetimeField('MIGRATION_DATE'))
				->configureTitle('Date of synchronization with baas-controller')
			,
			new ORM\Fields\StringField('MIGRATION_ID'),
			(new ORM\Fields\EnumField('MIGRATED'))
				->configureValues(['Y', 'N'])
				->configureDefaultValue('N')
			,
			//endregion
			(new ORM\Fields\StringField('CONSUMPTION_ID'))
				->configureTitle('Consumption id')
			,
			new ORM\Fields\Relations\Reference(
				'SERVICE',
				ServiceTable::class,
				['=this.SERVICE_CODE' => 'ref.CODE'],
				['join_type' => 'LEFT'],
			),
			new ORM\Fields\Relations\Reference(
				'PURCHASED_PACKAGE',
				PurchasedPackageTable::class,
				['=this.PURCHASED_PACKAGE_CODE' => 'ref.CODE'],
				['join_type' => 'INNER'],
			),
		];
	}
}
