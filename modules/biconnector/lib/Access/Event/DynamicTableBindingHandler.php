<?php

namespace Bitrix\BIConnector\Access\Event;

use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Access\TableGroup\SystemTableGroupMap;
use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDataset;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Crm\Model\Dynamic\Type as SmartProcessType;
use Bitrix\Crm\Model\Dynamic\TypeTable as SmartProcessTypeTable;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Result;

/**
 * Puts a table into its group at the moment the entity behind that table is created.
 *
 * Three kinds of tables appear on a live portal and cannot be declared in SystemTableGroupMap,
 * because their codes carry the identifier of the entity: the items and the product rows of a
 * smart process, the table of an automated solution and the table of an external dataset. The
 * first two belong to the CRM group, the third one to the group of the user tables - the same
 * places TableGroupBindingInstaller would put them.
 *
 * Until this handler existed, such a code reached a group only through a run of that installer -
 * that is, through a lifecycle point of the Superset instance or through the one-shot agent of a
 * release, which removes itself once the catalog it saw is covered. An entity created after that
 * run left its table bound to nothing, and a table without a binding is held by no group and
 * reachable by nobody.
 *
 * The subscription is to the ORM events of the tables that store the entities rather than to
 * events of the modules: Main\ORM\Event builds the name of the event from the entity itself, so no
 * module but ours has to know about this binding at all. The created entity is read from the
 * 'object' parameter of the event, which the ORM always supplies and which is typed, rather than
 * from the array of fields, where a renamed key of a foreign module would break us silently.
 *
 * The deletion is served the same way and by the same codes: the entity is gone, so the table it
 * named is gone with it, and a binding left behind would hand the groups of that table to the next
 * one created under the same name. The bindings are taken out of every group at once, the custom
 * ones included - a deletion is not a change of the composition anyone may have decided on by hand.
 *
 * A dataset whose name repeats a code the map declares for a system group gives back only its own
 * binding - the one to the group of the user tables. The groups the map declares keep that code:
 * the table behind it is not the one being deleted, and the name was free to take only because the
 * catalog of that moment could not answer.
 *
 * The deletion of a smart process and of a dataset costs two events instead of one. The code of the
 * table is built from a field of the row - the entity type id and the name - and by the time the
 * table is really gone the row is gone too: the object the ORM puts into the event is woken up by
 * the primary alone, so every other field of it reads as null on this path. So the code is resolved
 * while the row is still there, on OnDelete, and applied on OnAfterDelete, when the deletion has
 * really happened. A deletion that fails between the two leaves the binding alone. The table of an
 * automated solution needs none of this: its code is built from the primary the event itself brings.
 *
 * Nothing here is allowed to break the creation or the deletion of the entity: the handler answers
 * the event of another subsystem, so every failure ends in the module log instead of an exception.
 */
final class DynamicTableBindingHandler
{
	public const ERROR_NO_GROUP = 'BICONNECTOR_TABLE_GROUP_BINDING_NO_GROUP';

	private const LOG_SOURCE = 'table_group_binding_dynamic';

	private const KIND_SMART_PROCESS = 'smart_process';
	private const KIND_EXTERNAL_DATASET = 'external_dataset';

	/**
	 * Codes of the tables of the entities being deleted right now, by the kind of the entity and its
	 * primary. Filled on OnDelete, taken away on OnAfterDelete, and never kept beyond the hit.
	 *
	 * @var array<string, string[]>
	 */
	private static array $codesOfDeletedTables = [];

	public static function onAfterSmartProcessAdd(Event $event): void
	{
		self::run(static function () use ($event): ?Result {
			$entityTypeId = self::resolveSmartProcessTypeId($event);

			return $entityTypeId > 0 ? self::bindSmartProcessTables($entityTypeId) : null;
		});
	}

	public static function onAfterAutomatedSolutionAdd(Event $event): void
	{
		self::run(static function () use ($event): ?Result {
			$solutionId = self::resolvePrimaryId($event);

			return $solutionId > 0 ? self::bindAutomatedSolutionTable($solutionId) : null;
		});
	}

	public static function onAfterExternalDatasetAdd(Event $event): void
	{
		self::run(static function () use ($event): ?Result {
			$tableCode = self::resolveExternalDatasetCode($event);

			return $tableCode !== '' ? self::bindExternalDatasetTable($tableCode) : null;
		});
	}

	/**
	 * Remembers what the deletion of the smart process will take out of the groups, while the row
	 * that names it is still there.
	 */
	public static function onSmartProcessDelete(Event $event): void
	{
		self::run(static function () use ($event): ?Result {
			$entityTypeId = self::resolveDeletedSmartProcessTypeId($event);
			if ($entityTypeId > 0)
			{
				self::rememberDeletedCodes(
					self::KIND_SMART_PROCESS,
					$event,
					self::getSmartProcessTableCodes($entityTypeId),
				);
			}

			return null;
		});
	}

	public static function onAfterSmartProcessDelete(Event $event): void
	{
		self::run(static function () use ($event): ?Result {
			$codes = self::takeRememberedCodes(self::KIND_SMART_PROCESS, $event);

			return $codes === null ? null : self::unbindCodes($codes);
		});
	}

	public static function onAfterAutomatedSolutionDelete(Event $event): void
	{
		self::run(static function () use ($event): ?Result {
			$solutionId = self::resolvePrimaryId($event);

			return $solutionId > 0 ? self::unbindAutomatedSolutionTable($solutionId) : null;
		});
	}

	/**
	 * Remembers the name of the dataset being deleted: it is the code of its table, and after the
	 * deletion there is nowhere left to read it from.
	 */
	public static function onExternalDatasetDelete(Event $event): void
	{
		self::run(static function () use ($event): ?Result {
			$tableCode = self::resolveDeletedExternalDatasetCode($event);
			if ($tableCode !== '')
			{
				self::rememberDeletedCodes(self::KIND_EXTERNAL_DATASET, $event, [$tableCode]);
			}

			return null;
		});
	}

	public static function onAfterExternalDatasetDelete(Event $event): void
	{
		self::run(static function () use ($event): ?Result {
			$codes = self::takeRememberedCodes(self::KIND_EXTERNAL_DATASET, $event);
			if ($codes === null)
			{
				return null;
			}

			// Through the method of the dataset rather than through the plain removal: a code the map
			// declares is not taken out of the groups it declares, and that rule lives there.
			return self::unbindExternalDatasetTable((string)reset($codes));
		});
	}

	/**
	 * Binds the tables of the smart process - the items and their product rows - to the CRM group.
	 *
	 * @param int $entityTypeId Entity type id of the smart process.
	 */
	public static function bindSmartProcessTables(int $entityTypeId): Result
	{
		return self::bindCodes(
			self::getSmartProcessTableCodes($entityTypeId),
			SystemTableGroupMap::GROUP_CRM,
		);
	}

	/**
	 * Binds the table of the automated solution to the CRM group.
	 *
	 * @param int $solutionId Id of the automated solution.
	 */
	public static function bindAutomatedSolutionTable(int $solutionId): Result
	{
		return self::bindCodes(
			self::getAutomatedSolutionTableCodes($solutionId),
			SystemTableGroupMap::GROUP_CRM,
		);
	}

	/**
	 * Binds the table of the external dataset to the group of the user tables.
	 *
	 * The code of the table is the name of the dataset: that is the name every Dataset\Base builds
	 * its connector under, so it is the very code the catalog publishes.
	 *
	 * @param string $tableCode Name of the dataset.
	 */
	public static function bindExternalDatasetTable(string $tableCode): Result
	{
		return self::bindCodes([$tableCode], SystemTableGroupMap::getUserTablesGroupCode());
	}

	/**
	 * Takes the tables of the deleted smart process out of the groups they were put into.
	 *
	 * @param int $entityTypeId Entity type id of the smart process.
	 */
	public static function unbindSmartProcessTables(int $entityTypeId): Result
	{
		return self::unbindCodes(self::getSmartProcessTableCodes($entityTypeId));
	}

	/**
	 * Takes the table of the deleted automated solution out of the groups it was put into.
	 *
	 * @param int $solutionId Id of the automated solution.
	 */
	public static function unbindAutomatedSolutionTable(int $solutionId): Result
	{
		return self::unbindCodes(self::getAutomatedSolutionTableCodes($solutionId));
	}

	/**
	 * Takes the table of the deleted external dataset out of the groups it was put into.
	 *
	 * A code the map declares for a system group keeps the bindings of those groups: such a dataset
	 * only borrowed the name of a table that is not its own. Nothing stops a portal from having a
	 * dataset named telephony_call - the name is refused by the catalog of the moment, and a catalog
	 * that failed to answer refuses nothing - while the binding of that code to the telephony group
	 * was written for another table and outlives the dataset. What is taken back is the binding to
	 * the group of the user tables: that one belonged to the dataset itself and is as stale as any
	 * other binding of a deleted table.
	 *
	 * The tables of a smart process and of an automated solution need no such check: their codes
	 * leave the map together with the entity that named them.
	 *
	 * @param string $tableCode Name of the dataset.
	 */
	public static function unbindExternalDatasetTable(string $tableCode): Result
	{
		if (self::isDeclaredBySystemGroup($tableCode))
		{
			return self::unbindFromUserTables($tableCode);
		}

		return self::unbindCodes([$tableCode]);
	}

	private static function unbindFromUserTables(string $tableCode): Result
	{
		$result = new Result();

		$groupCode = SystemTableGroupMap::getUserTablesGroupCode();
		$groupId = self::getGroupId($groupCode);
		if ($groupId <= 0)
		{
			return $result->addError(new Error(
				"The {$groupCode} table group does not exist.",
				self::ERROR_NO_GROUP,
			));
		}

		$deleteResult = TableGroupService::deleteGroupBindings($groupId, [$tableCode]);
		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		return $result->setData(['unboundCodes' => $deleteResult->getData()['deletedCodes'] ?? []]);
	}

	private static function isDeclaredBySystemGroup(string $tableCode): bool
	{
		$userTablesGroupCode = SystemTableGroupMap::getUserTablesGroupCode();

		foreach (SystemTableGroupMap::getMap() as $groupCode => $groupTableCodes)
		{
			if ($groupCode !== $userTablesGroupCode && in_array($tableCode, $groupTableCodes, true))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The codes of the tables the smart process brings: its items and their product rows. One list
	 * for both directions, so that a deletion takes out exactly what a creation has put in.
	 *
	 * @return string[]
	 */
	private static function getSmartProcessTableCodes(int $entityTypeId): array
	{
		return [
			'crm_dynamic_items_' . $entityTypeId,
			'crm_dynamic_items_prod_' . $entityTypeId,
		];
	}

	/**
	 * @return string[]
	 */
	private static function getAutomatedSolutionTableCodes(int $solutionId): array
	{
		return ['crm_automated_solution_' . $solutionId];
	}

	/**
	 * @param string[] $codes
	 * @param string $groupCode Code of the system group the tables belong to.
	 *
	 * @return Result Data: ['boundCodes' => string[]].
	 */
	private static function bindCodes(array $codes, string $groupCode): Result
	{
		$result = new Result();

		// The rule of the installer holds here too: a code bound anywhere is already arranged, and a
		// second answer to the same question would move a table an administrator has moved by hand.
		$codesToBind = array_values(array_diff($codes, self::getBoundCodes($codes)));
		if (empty($codesToBind))
		{
			return $result->setData(['boundCodes' => []]);
		}

		$groupId = self::getGroupId($groupCode);
		if ($groupId <= 0)
		{
			// The groups are created by the installer of the access subsystem. Until it has run there
			// is nothing to bind to, and the run itself arranges the whole catalog anyway.
			return $result->addError(new Error(
				"The {$groupCode} table group does not exist.",
				self::ERROR_NO_GROUP,
			));
		}

		$addResult = TableGroupService::addGroupBindings($groupId, $codesToBind);
		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		return $result->setData(['boundCodes' => $codesToBind]);
	}

	/**
	 * @param string[] $codes
	 *
	 * @return Result Data: ['unboundCodes' => string[]].
	 */
	private static function unbindCodes(array $codes): Result
	{
		$result = new Result();

		$deleteResult = TableGroupService::deleteTableBindings($codes);
		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		return $result->setData(['unboundCodes' => $deleteResult->getData()['deletedCodes'] ?? []]);
	}

	/**
	 * @param string $kind
	 * @param Event $event
	 * @param string[] $codes
	 */
	private static function rememberDeletedCodes(string $kind, Event $event, array $codes): void
	{
		self::$codesOfDeletedTables[self::getDeletionKey($kind, $event)] = $codes;
	}

	/**
	 * @return string[]|null Null when this deletion has remembered no table of ours.
	 */
	private static function takeRememberedCodes(string $kind, Event $event): ?array
	{
		$key = self::getDeletionKey($kind, $event);
		$codes = self::$codesOfDeletedTables[$key] ?? null;
		unset(self::$codesOfDeletedTables[$key]);

		return $codes;
	}

	private static function getDeletionKey(string $kind, Event $event): string
	{
		return $kind . ':' . self::resolvePrimaryId($event);
	}

	/**
	 * @param callable():(Result|null) $binding Returns null when the event names no entity.
	 */
	private static function run(callable $binding): void
	{
		try
		{
			$result = $binding();
			if ($result !== null && !$result->isSuccess())
			{
				Logger::logErrors($result->getErrors(), ['source' => self::LOG_SOURCE]);
			}
		}
		catch (\Throwable $exception)
		{
			Logger::logErrors(
				[new Error($exception->getMessage(), self::LOG_SOURCE)],
				['source' => self::LOG_SOURCE],
			);
		}
	}

	private static function resolveSmartProcessTypeId(Event $event): int
	{
		$type = $event->getParameter('object');

		return $type instanceof SmartProcessType ? (int)$type->getEntityTypeId() : 0;
	}

	/**
	 * The primary the ORM reports - a scalar of the core rather than a field name of a foreign
	 * module. It is the id of the automated solution on its own events, and the key a deletion of a
	 * smart process or of a dataset is remembered under on the others.
	 */
	private static function resolvePrimaryId(Event $event): int
	{
		$id = $event->getParameter('id');
		if (is_array($id))
		{
			$id = $id['ID'] ?? reset($id);
		}

		return (int)$id;
	}

	/**
	 * The entity type id of the smart process being deleted, read from the row while it is still
	 * there: the object of a deletion event carries the primary and nothing else unless the caller
	 * deleted it through an object of its own.
	 */
	private static function resolveDeletedSmartProcessTypeId(Event $event): int
	{
		$entityTypeId = self::resolveSmartProcessTypeId($event);
		if ($entityTypeId > 0)
		{
			return $entityTypeId;
		}

		$id = self::resolvePrimaryId($event);
		if ($id <= 0)
		{
			return 0;
		}

		$row = SmartProcessTypeTable::getList([
			'select' => ['ENTITY_TYPE_ID'],
			'filter' => ['=ID' => $id],
			'limit' => 1,
		])->fetch();

		return $row ? (int)$row['ENTITY_TYPE_ID'] : 0;
	}

	/**
	 * The name of the dataset being deleted, read the same way and for the same reason.
	 */
	private static function resolveDeletedExternalDatasetCode(Event $event): string
	{
		$tableCode = self::resolveExternalDatasetCode($event);
		if ($tableCode !== '')
		{
			return $tableCode;
		}

		$id = self::resolvePrimaryId($event);
		if ($id <= 0)
		{
			return '';
		}

		$row = ExternalDatasetTable::getList([
			'select' => ['NAME'],
			'filter' => ['=ID' => $id],
			'limit' => 1,
		])->fetch();

		return $row ? (string)$row['NAME'] : '';
	}

	private static function resolveExternalDatasetCode(Event $event): string
	{
		$dataset = $event->getParameter('object');

		return $dataset instanceof ExternalDataset ? (string)$dataset->getName() : '';
	}

	/**
	 * @param string[] $codes
	 *
	 * @return string[] Codes of the given ones that have a binding to any group.
	 */
	private static function getBoundCodes(array $codes): array
	{
		$rows = TableGroupBindingTable::getList([
			'select' => ['TABLE_CODE'],
			'filter' => ['@TABLE_CODE' => $codes],
			'group' => ['TABLE_CODE'],
		])->fetchAll();

		return array_column($rows, 'TABLE_CODE');
	}

	private static function getGroupId(string $groupCode): int
	{
		$group = TableGroupTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=CODE' => $groupCode,
				'=TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM,
			],
			'limit' => 1,
		])->fetch();

		return $group ? (int)$group['ID'] : 0;
	}
}
