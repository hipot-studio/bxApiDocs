<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Model;

use Bitrix\Booking\Internals\Model\Trait\UpdateByFilterTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class BookingPaymentTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_BookingPayment_Query query()
 * @method static EO_BookingPayment_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_BookingPayment_Result getById($id)
 * @method static EO_BookingPayment_Result getList(array $parameters = [])
 * @method static EO_BookingPayment_Entity getEntity()
 * @method static \Bitrix\Booking\Internals\Model\EO_BookingPayment createObject($setDefaultValues = true)
 * @method static \Bitrix\Booking\Internals\Model\EO_BookingPayment_Collection createCollection()
 * @method static \Bitrix\Booking\Internals\Model\EO_BookingPayment wakeUpObject($row)
 * @method static \Bitrix\Booking\Internals\Model\EO_BookingPayment_Collection wakeUpCollection($rows)
 */
class BookingPaymentTable extends DataManager
{
	use UpdateByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_booking_booking_payment';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('BOOKING_ID'))
				->configureRequired(),

			(new IntegerField('PAYMENT_ID'))
				->configureRequired(),

			(new BooleanField('IS_PAID'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
				->configureRequired(),

			(new BooleanField('IS_PAID_MANUALLY'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
				->configureRequired(),
		];
	}
}
