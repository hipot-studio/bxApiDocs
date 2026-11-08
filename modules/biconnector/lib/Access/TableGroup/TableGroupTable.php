<?php

namespace Bitrix\BIConnector\Access\TableGroup;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * Class TableGroupTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TableGroup_Query query()
 * @method static EO_TableGroup_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TableGroup_Result getById($id)
 * @method static EO_TableGroup_Result getList(array $parameters = [])
 * @method static EO_TableGroup_Entity getEntity()
 * @method static \Bitrix\BIConnector\Access\TableGroup\TableGroup createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Access\TableGroup\TableGroupCollection createCollection()
 * @method static \Bitrix\BIConnector\Access\TableGroup\TableGroup wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Access\TableGroup\TableGroupCollection wakeUpCollection($rows)
 */
final class TableGroupTable extends DataManager
{
	use DeleteByFilterTrait;

	public const GROUP_TYPE_SYSTEM = 'SYSTEM';
	public const GROUP_TYPE_CUSTOM = 'CUSTOM';

	/**
	 * Position of a group that did not ask for one. The system groups are placed by tens ahead of
	 * it, so a custom group lands after all of them and custom groups are ordered among themselves
	 * by id, that is in the order they were created.
	 */
	public const DEFAULT_SORT = 100;

	public static function getTableName(): string
	{
		return 'b_biconnector_table_group';
	}

	public static function getObjectClass(): string
	{
		return TableGroup::class;
	}

	public static function getCollectionClass(): string
	{
		return TableGroupCollection::class;
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,

			(new Fields\StringField('CODE'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 50))
			,

			(new Fields\StringField('NAME'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 512))
			,

			(new Fields\EnumField('TYPE'))
				->configureRequired()
				->configureValues([
					self::GROUP_TYPE_SYSTEM,
					self::GROUP_TYPE_CUSTOM,
				])
				->configureDefaultValue(self::GROUP_TYPE_CUSTOM)
			,

			// The order the groups are shown in. Declared here rather than derived from the id:
			// a portal that already has the groups cannot renumber them, and a group added later
			// has to be able to take a place in the middle of the list.
			(new Fields\IntegerField('SORT'))
				->configureRequired()
				->configureDefaultValue(self::DEFAULT_SORT)
			,

			(new Fields\DatetimeField('DATE_CREATE'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime())
			,

			(new Fields\DatetimeField('DATE_MODIFY'))
				->configureRequired()
				->configureDefaultValue(fn() => new DateTime())
			,

			// A system group belongs to the installer and a group made before the column existed
			// belongs to nobody the portal still knows, so neither of them names an author.
			(new Fields\IntegerField('CREATED_BY_ID'))
				->configureNullable()
			,

			(new Fields\IntegerField('UPDATED_BY_ID'))
				->configureNullable()
			,
		];
	}

	/**
	 * Deletes bindings of the removed group.
	 *
	 * @param Event $event
	 * @return EventResult
	 */
	public static function onAfterDelete(Event $event): EventResult
	{
		$result = new EventResult();

		$object = $event->getParameter('object');

		TableGroupBindingTable::deleteByFilter([
			'GROUP_ID' => $object->getId(),
		]);

		return $result;
	}

	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();

		$fields = $event->getParameter('fields');
		$fields['DATE_MODIFY'] = new DateTime();

		$result->modifyFields($fields);

		return $result;
	}
}
