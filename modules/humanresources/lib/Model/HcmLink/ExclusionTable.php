<?php

namespace Bitrix\HumanResources\Model\HcmLink;

use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

/**
 * Class ExclusionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Exclusion_Query query()
 * @method static EO_Exclusion_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Exclusion_Result getById($id)
 * @method static EO_Exclusion_Result getList(array $parameters = [])
 * @method static EO_Exclusion_Entity getEntity()
 * @method static \Bitrix\HumanResources\Model\HcmLink\Exclusion createObject($setDefaultValues = true)
 * @method static \Bitrix\HumanResources\Model\HcmLink\ExclusionCollection createCollection()
 * @method static \Bitrix\HumanResources\Model\HcmLink\Exclusion wakeUpObject($row)
 * @method static \Bitrix\HumanResources\Model\HcmLink\ExclusionCollection wakeUpCollection($rows)
 */
class ExclusionTable extends ORM\Data\DataManager
{
	use DeleteByFilterTrait;

	public static function getObjectClass(): string
	{
		return Exclusion::class;
	}

	public static function getCollectionClass(): string
	{
		return ExclusionCollection::class;
	}

	public static function getTableName(): string
	{
		return 'b_hr_hcmlink_exclusion';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureTitle('ID')
			,
			(new ORM\Fields\IntegerField('COMPANY_ID'))
				->configureRequired()
				->configureTitle('Company id')
			,
			(new ORM\Fields\Relations\Reference(
				'COMPANY',
				CompanyTable::class,
				Join::on('this.COMPANY_ID', 'ref.ID')
			))
			,
			(new ORM\Fields\IntegerField('USER_ID'))
				->configureRequired()
				->configureTitle('Excluded user id')
			,
			(new ORM\Fields\IntegerField('CREATED_BY'))
				->configureRequired()
				->configureTitle('Author user id')
			,
			(new ORM\Fields\DatetimeField('CREATED_AT'))
				->configureDefaultValue(new DateTime())
				->configureTitle('Exclusion created at')
			,
		];
	}
}
