<?php

namespace Bitrix\Recyclebin\Internals\Models;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Result;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\Relations\Reference;

/**
 * Class RecyclebinUfTable
 *
 * @package Bitrix\Recyclebin\Model
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RecyclebinUf_Query query()
 * @method static EO_RecyclebinUf_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RecyclebinUf_Result getById($id)
 * @method static EO_RecyclebinUf_Result getList(array $parameters = [])
 * @method static EO_RecyclebinUf_Entity getEntity()
 * @method static \Bitrix\Recyclebin\Internals\Models\EO_RecyclebinUf createObject($setDefaultValues = true)
 * @method static \Bitrix\Recyclebin\Internals\Models\EO_RecyclebinUf_Collection createCollection()
 * @method static \Bitrix\Recyclebin\Internals\Models\EO_RecyclebinUf wakeUpObject($row)
 * @method static \Bitrix\Recyclebin\Internals\Models\EO_RecyclebinUf_Collection wakeUpCollection($rows)
 */
final class RecyclebinUfTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_recyclebin_entity_uf';
	}

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			new IntegerField('RECYCLEBIN_ID'),
			new StringField('UF_ENTITY_ID'),
			new ArrayField('DATA'),
			new Reference(
				'RECYCLEBIN',
				RecyclebinTable::class,
				Join::on('this.RECYCLEBIN_ID', 'ref.ID')
			),
		];
	}

	/**
	 * @throws SqlQueryException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public static function deleteByRecyclebinId(mixed $recyclebinId): Result
	{
		$connection = self::getEntity()->getConnection();
		$sql = "DELETE FROM " . self::getTableName() . " WHERE RECYCLEBIN_ID = " . (int)$recyclebinId;

		return $connection->query($sql);
	}
}
