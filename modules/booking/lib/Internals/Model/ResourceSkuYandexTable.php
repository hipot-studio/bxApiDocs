<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class YandexResourceSkuTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ResourceSkuYandex_Query query()
 * @method static EO_ResourceSkuYandex_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ResourceSkuYandex_Result getById($id)
 * @method static EO_ResourceSkuYandex_Result getList(array $parameters = [])
 * @method static EO_ResourceSkuYandex_Entity getEntity()
 * @method static \Bitrix\Booking\Internals\Model\EO_ResourceSkuYandex createObject($setDefaultValues = true)
 * @method static \Bitrix\Booking\Internals\Model\EO_ResourceSkuYandex_Collection createCollection()
 * @method static \Bitrix\Booking\Internals\Model\EO_ResourceSkuYandex wakeUpObject($row)
 * @method static \Bitrix\Booking\Internals\Model\EO_ResourceSkuYandex_Collection wakeUpCollection($rows)
 */
class ResourceSkuYandexTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_booking_resource_sku_yandex';
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
