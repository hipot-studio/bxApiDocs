<?php
namespace Bitrix\Disk\Internals;

use Bitrix\Disk\Internal\Service\UploadCleanup\MarkerService;
use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;
use Bitrix\Disk;

/**
 * Class TrackedObjectTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TrackedObject_Query query()
 * @method static EO_TrackedObject_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TrackedObject_Result getById($id)
 * @method static EO_TrackedObject_Result getList(array $parameters = [])
 * @method static EO_TrackedObject_Entity getEntity()
 * @method static \Bitrix\Disk\Internals\EO_TrackedObject createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internals\EO_TrackedObject_Collection createCollection()
 * @method static \Bitrix\Disk\Internals\EO_TrackedObject wakeUpObject($row)
 * @method static \Bitrix\Disk\Internals\EO_TrackedObject_Collection wakeUpCollection($rows)
 */
final class TrackedObjectTable extends DataManager
{
	public static function onBeforeAdd(Entity\Event $event): Entity\EventResult
	{
		$result = new Entity\EventResult();
		$fields = $event->getParameter('fields');
		$objectId = (int)($fields['REAL_OBJECT_ID'] ?? $fields['OBJECT_ID'] ?? 0);
		if ($objectId > 0 && !(new MarkerService())->revokeBeforeUse($objectId))
		{
			$result->addError(new Entity\EntityError(MarkerService::ERROR_FILE_IS_BEING_REMOVED));
		}

		return $result;
	}

	public static function getTableName()
	{
		return 'b_disk_tracked_object';
	}

	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Entity\IntegerField('USER_ID', ['required' => true]),
			new Entity\IntegerField('OBJECT_ID', ['required' => true]),
			new Entity\ReferenceField(
				'FILE',
				Disk\Internals\ObjectTable::class,
				['=this.OBJECT_ID' => 'ref.ID']
			),
			new Entity\IntegerField('REAL_OBJECT_ID', ['required' => true]),
			new Entity\IntegerField('ATTACHED_OBJECT_ID', ['required' => false]),
			new Entity\IntegerField('TYPE_FILE', ['required' => false]),
			new Entity\DatetimeField('CREATE_TIME', ['default_value' => function(){return new DateTime();}]),
			new Entity\DatetimeField('UPDATE_TIME', ['default_value' => function(){return new DateTime();}]),
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
