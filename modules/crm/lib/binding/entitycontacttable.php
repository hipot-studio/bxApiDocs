<?php

namespace Bitrix\Crm\Binding;

use Bitrix\Crm\ContactTable;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Data\AddStrategy;

/**
 * Class EntityContactTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_EntityContact_Query query()
 * @method static EO_EntityContact_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_EntityContact_Result getById($id)
 * @method static EO_EntityContact_Result getList(array $parameters = [])
 * @method static EO_EntityContact_Entity getEntity()
 * @method static \Bitrix\Crm\Binding\EO_EntityContact createObject($setDefaultValues = true)
 * @method static \Bitrix\Crm\Binding\EO_EntityContact_Collection createCollection()
 * @method static \Bitrix\Crm\Binding\EO_EntityContact wakeUpObject($row)
 * @method static \Bitrix\Crm\Binding\EO_EntityContact_Collection wakeUpCollection($rows)
 */
class EntityContactTable extends DataManager
{
	use AddStrategy\Trait\MergeByDefaultTrait;

	public static function getTableName(): string
	{
		return 'b_crm_entity_contact';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ENTITY_TYPE_ID'))
				->configurePrimary(),
			(new IntegerField('ENTITY_ID'))
				->configurePrimary(),
			(new IntegerField('CONTACT_ID'))
				->configurePrimary(),
			(new Reference('CONTACT', ContactTable::class, Join::on('this.CONTACT_ID', 'ref.ID'))),
			(new IntegerField('SORT'))
				->configureRequired()
				->configureDefaultValue(0),
			(new IntegerField('ROLE_ID'))
				->configureRequired()
				->configureDefaultValue(EntityBinding::ROLE_UNDEFINED),
			(new BooleanField('IS_PRIMARY'))
				->configureRequired()
				->configureStorageValues('N', 'Y')
				->configureDefaultValue('N'),
		];
	}

	public static function deleteByItem(int $entityTypeId, int $entityId): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		/** @noinspection SqlResolve */
		$connection->query(sprintf(
			'DELETE FROM %s WHERE ENTITY_TYPE_ID = %d AND ENTITY_ID = %d',
			$helper->quote(static::getTableName()),
			$helper->convertToDbInteger($entityTypeId),
			$helper->convertToDbInteger($entityId)
		));
	}

	final public static function deleteByContact(int $contactId): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		/** @noinspection SqlResolve */
		$connection->query(sprintf(
			'DELETE FROM %s WHERE CONTACT_ID = %d',
			$helper->quote(static::getTableName()),
			$helper->convertToDbInteger($contactId),
		));
	}

	public static function getContactIds(int $entityTypeId, int $entityId): array
	{
		return static::getList([
			'select' => ['CONTACT_ID'],
			'order' => [
				'SORT' => 'ASC',
			],
			'filter' => [
				'=ENTITY_TYPE_ID' => $entityTypeId,
				'=ENTITY_ID' => $entityId,
			],
		])->fetchCollection()->getContactIdList();
	}

	public static function getBulkContactBindings(int $entityTypeId, array $entityIds): array
	{
		\Bitrix\Main\Type\Collection::normalizeArrayValuesByInt($entityIds, false);
		if (empty($entityIds))
		{
			return [];
		}

		$map = [];
		foreach ($entityIds as $entityId)
		{
			$map[$entityId] = [];
		}

		$collection = static::getList([
			'select' => ['ENTITY_ID', 'CONTACT_ID', 'ROLE_ID', 'IS_PRIMARY', 'SORT'],
			'filter' => [
				'=ENTITY_TYPE_ID' => $entityTypeId,
				'@ENTITY_ID' => $entityIds,
			],
			'order' => [
				'ENTITY_ID' => 'ASC',
				'SORT' => 'ASC',
			],
		])->fetchCollection();

		foreach ($collection as $row)
		{
			$map[$row->getEntityId()][] = [
				'CONTACT_ID' => $row->getContactId(),
				'ROLE_ID' => $row->getRoleId(),
				'IS_PRIMARY' => $row->getIsPrimary() ? 'Y' : 'N',
				'SORT' => $row->getSort(),
			];
		}

		EntityBinding::normalizeEntityBindings(\CCrmOwnerType::Contact, $map);

		return $map;
	}

	/**
	 * @param int $entityTypeId
	 * @param int $contactId
	 * @return array
	 */
	public static function getEntityIds(int $entityTypeId, int $contactId): array
	{
		return static::getList([
			'select' => ['ENTITY_ID'],
			'order' => [
				'SORT' => 'ASC',
			],
			'filter' => [
				'=ENTITY_TYPE_ID' => $entityTypeId,
				'=CONTACT_ID' => $contactId,
			],
		])->fetchCollection()->getEntityIdList();
	}

	/**
	 * Prepares SQL join that restricts a contact list to contacts bound to the specified item.
	 *
	 * @param int $entityTypeId Owner entity type ID.
	 * @param int $entityId Owner entity ID.
	 * @param string $tableAlias Alias of the contact table in the main query.
	 * @return string
	 * @throws Main\ArgumentException
	 */
	public static function prepareFilterJoinSql(int $entityTypeId, int $entityId, string $tableAlias): string
	{
		if ($entityTypeId <= 0)
		{
			throw new Main\ArgumentException('Must be greater than zero', 'entityTypeId');
		}

		if ($entityId <= 0)
		{
			throw new Main\ArgumentException('Must be greater than zero', 'entityId');
		}

		return "INNER JOIN b_crm_entity_contact EC ON EC.ENTITY_TYPE_ID = {$entityTypeId}"
			. " AND EC.ENTITY_ID = {$entityId} AND EC.CONTACT_ID = {$tableAlias}.ID";
	}
}
