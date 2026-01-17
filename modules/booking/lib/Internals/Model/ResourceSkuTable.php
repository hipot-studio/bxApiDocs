<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class ResourceSkuTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ResourceSku_Query query()
 * @method static EO_ResourceSku_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ResourceSku_Result getById($id)
 * @method static EO_ResourceSku_Result getList(array $parameters = [])
 * @method static EO_ResourceSku_Entity getEntity()
 * @method static \Bitrix\Booking\Internals\Model\EO_ResourceSku createObject($setDefaultValues = true)
 * @method static \Bitrix\Booking\Internals\Model\EO_ResourceSku_Collection createCollection()
 * @method static \Bitrix\Booking\Internals\Model\EO_ResourceSku wakeUpObject($row)
 * @method static \Bitrix\Booking\Internals\Model\EO_ResourceSku_Collection wakeUpCollection($rows)
 */
class ResourceSkuTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_booking_resource_sku';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('RESOURCE_ID'))
				->configureRequired(),

			(new IntegerField('SKU_ID'))
				->configureRequired(),

			(new Reference(
				'RESOURCE',
				ResourceTable::class,
				Join::on('this.RESOURCE_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_LEFT),
		];
	}
}
