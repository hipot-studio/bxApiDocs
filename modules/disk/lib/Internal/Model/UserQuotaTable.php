<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Disk\Internal\Enum\UsageStatus;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Class UserQuotaTable
 *
 * One row per employee with a personal storage quota: the quota itself, the last known used size
 * with its actuality status and the service fields of the recalculation queue. A missing row means
 * the employee works without a personal limit.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_UserQuota_Query query()
 * @method static EO_UserQuota_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_UserQuota_Result getById($id)
 * @method static EO_UserQuota_Result getList(array $parameters = [])
 * @method static EO_UserQuota_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_UserQuota createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_UserQuota_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_UserQuota wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_UserQuota_Collection wakeUpCollection($rows)
 */
class UserQuotaTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_disk_user_quota';
	}

	/**
	 * @return array
	 * @throws SystemException
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
				->configureUnique()
			,
			(new IntegerField('QUOTA_SIZE'))
				->configureRequired()
			,
			// Nullable on purpose: null is "never calculated", a state IntegerField would otherwise
			// hand over as a zero - and a zero is a size the quota check has every reason to trust.
			(new IntegerField('USED_SIZE'))
				->configureNullable()
			,
			(new EnumField('USED_SIZE_STATUS'))
				->configureRequired()
				->configureValues(array_column(UsageStatus::cases(), 'value'))
				->configureDefaultValue(UsageStatus::Stale->value)
			,
			(new DatetimeField('USED_SIZE_TIME'))
			,
			(new BooleanField('RECALC_REQUESTED'))
				->configureStorageValues(0, 1)
				->configureDefaultValue(false)
			,
			// Birth of a row is not a status transition, so the column has no database default:
			// only the insert and later status transitions write it.
			(new DatetimeField('STATUS_TIME'))
				->configureRequired()
				->configureDefaultValue(static fn(): DateTime => new DateTime())
			,
		];
	}
}
