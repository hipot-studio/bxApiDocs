<?php
namespace Bitrix\Disk\Internals;

use Bitrix\Main\Entity;

/**
 * Class ObjectOptionsTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ObjectOptions_Query query()
 * @method static EO_ObjectOptions_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ObjectOptions_Result getById($id)
 * @method static EO_ObjectOptions_Result getList(array $parameters = [])
 * @method static EO_ObjectOptions_Entity getEntity()
 * @method static \Bitrix\Disk\Internals\EO_ObjectOptions createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internals\EO_ObjectOptions_Collection createCollection()
 * @method static \Bitrix\Disk\Internals\EO_ObjectOptions wakeUpObject($row)
 * @method static \Bitrix\Disk\Internals\EO_ObjectOptions_Collection wakeUpCollection($rows)
 */
final class ObjectOptionsTable extends DataManager
{

	public const NAME_ALLOW_DOWNLOAD_ON_READ = 'dw-on-read';
	public const NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ = 'pub-mng-on-read';

	public static function getTableName()
	{
		return 'b_disk_object_options';
	}

	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Entity\IntegerField('OBJECT_ID', ['required' => true]),
			new Entity\StringField('NAME', ['required' => true]),
			new Entity\TextField('VALUE', ['required' => true]),
		];
	}

	public static function updateBatch(array $fields, array $filter)
	{
		parent::updateBatch($fields, $filter);
	}

	public static function deleteBatch(array $filter)
	{
		parent::deleteBatch($filter);
	}
}