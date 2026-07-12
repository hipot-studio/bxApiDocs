<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Model;

use Bitrix\Main\Access\Entity\DataManager;
use Bitrix\Main\ORM\Data\AddStrategy\Contract\AddStrategy;
use Bitrix\Main\ORM\Data\AddStrategy\InsertIgnore;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class ViewedAbsenceTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ViewedAbsence_Query query()
 * @method static EO_ViewedAbsence_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ViewedAbsence_Result getById($id)
 * @method static EO_ViewedAbsence_Result getList(array $parameters = [])
 * @method static EO_ViewedAbsence_Entity getEntity()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_ViewedAbsence createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_ViewedAbsence_Collection createCollection()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_ViewedAbsence wakeUpObject($row)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_ViewedAbsence_Collection wakeUpCollection($rows)
 */
class ViewedAbsenceTable extends DataManager
{
	use DeleteByFilterTrait;
	use AddInsertIgnoreTrait;

	public static function getTableName(): string
	{
		return 'b_tasks_viewed_absence';
	}

	protected static function getInsertIgnoreStrategy(): AddStrategy
	{
		return new InsertIgnore(
			static::getEntity(),
			['VIEWED_BY', 'USER_ID', 'ABSENCE_ID']
		);
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('VIEWED_BY'))
				->configureRequired(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new IntegerField('ABSENCE_ID'))
				->configureRequired(),

			(new DatetimeField('ABSENCE_END'))
				->configureRequired(),
		];
	}
}
