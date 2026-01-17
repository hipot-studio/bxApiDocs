<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Model;

use Bitrix\Booking\Internals\Model\Trait\UpdateByFilterTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class BookingSkuTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_BookingSku_Query query()
 * @method static EO_BookingSku_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_BookingSku_Result getById($id)
 * @method static EO_BookingSku_Result getList(array $parameters = [])
 * @method static EO_BookingSku_Entity getEntity()
 * @method static \Bitrix\Booking\Internals\Model\EO_BookingSku createObject($setDefaultValues = true)
 * @method static \Bitrix\Booking\Internals\Model\EO_BookingSku_Collection createCollection()
 * @method static \Bitrix\Booking\Internals\Model\EO_BookingSku wakeUpObject($row)
 * @method static \Bitrix\Booking\Internals\Model\EO_BookingSku_Collection wakeUpCollection($rows)
 */
final class BookingSkuTable extends DataManager
{
	use DeleteByFilterTrait;
	use UpdateByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_booking_booking_sku';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('BOOKING_ID'))
				->configureRequired(),

			(new IntegerField('SKU_ID'))
				->configureRequired(),

			(new IntegerField('PRODUCT_ROW_ID'))
				->configureNullable()
				->configureDefaultValue(null),

			(new Reference(
				'BOOKING',
				BookingTable::class,
				Join::on('this.BOOKING_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_LEFT),
		];
	}
}
