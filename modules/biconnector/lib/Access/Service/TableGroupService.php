<?php

namespace Bitrix\BIConnector\Access\Service;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Table\PermissionCacheResetNotifier;
use Bitrix\BIConnector\Access\Table\TableVisibilityService;
use Bitrix\BIConnector\Access\TableGroup\SystemTableGroupMap;
use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * The only write boundary for table groups and their bindings, and the read point of the axis.
 *
 * Writing, reading for the screen of the rights and the check of the catalog coverage live in one
 * class on purpose: they speak about the same rows, and a service of its own for every question
 * asked of them buys nothing but another name to remember.
 */
final class TableGroupService
{
	public const ERROR_CODE_EXISTS = 'BICONNECTOR_TABLE_GROUP_CODE_EXISTS';
	public const ERROR_INVALID_GROUP = 'BICONNECTOR_TABLE_GROUP_INVALID_GROUP';
	public const ERROR_EMPTY_NAME = 'BICONNECTOR_TABLE_GROUP_EMPTY_NAME';
	public const ERROR_GROUP_NOT_FOUND = 'BICONNECTOR_TABLE_GROUP_NOT_FOUND';
	public const ERROR_NOT_CUSTOM_GROUP = 'BICONNECTOR_TABLE_GROUP_NOT_CUSTOM';
	public const ERROR_TABLES_WOULD_MOVE = 'BICONNECTOR_TABLE_GROUP_DELETE_WOULD_MOVE_TABLES';
	public const ERROR_TABLE_CODE_RESERVED = 'BICONNECTOR_TABLE_GROUP_TABLE_CODE_RESERVED';

	public const ERROR_TABLES_WITHOUT_GROUP = 'BICONNECTOR_TABLES_WITHOUT_GROUP';

	/**
	 * Tables the refusal text lists before it turns to counting.
	 */
	public const MAX_NAMES_IN_REFUSAL = 3;

	public const TABLE_TYPE_SYSTEM = 'SYSTEM';

	public const TABLE_TYPE_CUSTOM = 'CUSTOM';

	private const CODE_LENGTH_LIMIT = 50;
	private const CODE_GENERATION_ATTEMPTS = 10;

	private const POPUP_CACHE_TTL = 3600;

	/**
	 * Creates a system table group.
	 *
	 * The group gets no author: it is created by the installer, and the portal it appears on has
	 * nobody to attribute it to.
	 *
	 * @param string $code Unique group code.
	 * @param string $name Group name.
	 * @param int|null $sort Position of the group in the list, the default one when omitted.
	 *
	 * @return Result Data: ['id' => int].
	 */
	public static function createSystemGroup(string $code, string $name, ?int $sort = null): Result
	{
		$result = new Result();

		$existingGroup = TableGroupTable::getList([
			'select' => ['ID'],
			'filter' => ['=CODE' => $code],
			'limit' => 1,
		])->fetch();

		if ($existingGroup)
		{
			return $result->addError(
				new Error("Table group with code {$code} already exists.", self::ERROR_CODE_EXISTS)
			);
		}

		try
		{
			$addResult = TableGroupTable::add([
				'CODE' => $code,
				'NAME' => $name,
				'TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM,
				'SORT' => $sort ?? TableGroupTable::DEFAULT_SORT,
			]);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error($exception->getMessage()));
		}

		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		self::onGroupsChanged();

		return $result->setData(['id' => (int)$addResult->getId()]);
	}

	/**
	 * Creates a custom table group.
	 *
	 * The group takes the default position and a generated code: neither is up to the caller, since
	 * custom groups follow the system ones and are ordered among themselves by the creation order.
	 *
	 * The author is the person of the hit: a custom group is created from the section of the tables
	 * and from nowhere else.
	 *
	 * @param string $name Group name.
	 *
	 * @return Result Data: ['id' => int].
	 */
	public static function createCustomGroup(string $name): Result
	{
		$result = self::writeCustomGroup($name);
		if ($result->isSuccess())
		{
			self::onGroupsChanged();
		}

		return $result;
	}

	/**
	 * Writes the row of a new custom group, whoever answers for the transaction and the caches.
	 *
	 * @param string $name Group name.
	 *
	 * @return Result Data: ['id' => int].
	 */
	private static function writeCustomGroup(string $name): Result
	{
		$result = new Result();

		$name = trim($name);
		if ($name === '')
		{
			return $result->addError(
				new Error('Table group name must not be empty.', self::ERROR_EMPTY_NAME)
			);
		}

		$authorId = self::getAuthorId();

		try
		{
			$addResult = TableGroupTable::add([
				'CODE' => self::generateGroupCode(),
				'NAME' => $name,
				'TYPE' => TableGroupTable::GROUP_TYPE_CUSTOM,
				'SORT' => TableGroupTable::DEFAULT_SORT,
				'CREATED_BY_ID' => $authorId,
				'UPDATED_BY_ID' => $authorId,
			]);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error($exception->getMessage()));
		}

		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		return $result->setData(['id' => (int)$addResult->getId()]);
	}

	/**
	 * Renames a custom table group.
	 *
	 * The name the group already has is not a change: neither the row nor the modification date is
	 * touched, and the caller is told so by the returned data.
	 *
	 * @param int $groupId Group id.
	 * @param string $name New group name.
	 *
	 * @return Result Data: ['isChanged' => bool].
	 */
	public static function renameCustomGroup(int $groupId, string $name): Result
	{
		$result = self::writeGroupName($groupId, $name);
		if ($result->isSuccess() && $result->getData()['isChanged'])
		{
			self::onGroupsChanged();
		}

		return $result;
	}

	/**
	 * Renames a custom table group inside a transaction the caller already holds.
	 *
	 * The caches are left to the caller for the same reason the transaction is: emptying them here
	 * would mean emptying them over a write that the caller can still roll back.
	 *
	 * @param int $groupId Group id.
	 * @param string $name New group name.
	 *
	 * @return Result Data: ['isChanged' => bool].
	 */
	public static function renameCustomGroupWithinTransaction(int $groupId, string $name): Result
	{
		return self::writeGroupName($groupId, $name);
	}

	/**
	 * Writes the new name of the group, whoever answers for the caches afterwards.
	 *
	 * @param int $groupId
	 * @param string $name
	 *
	 * @return Result Data: ['isChanged' => bool].
	 */
	private static function writeGroupName(int $groupId, string $name): Result
	{
		$result = new Result();

		if ($groupId <= 0)
		{
			return $result->addError(
				new Error('Table group id must be a positive integer.', self::ERROR_INVALID_GROUP)
			);
		}

		$name = trim($name);
		if ($name === '')
		{
			return $result->addError(
				new Error('Table group name must not be empty.', self::ERROR_EMPTY_NAME)
			);
		}

		$group = TableGroupTable::getById($groupId)->fetchObject();

		if ($group === null)
		{
			return $result->addError(
				new Error("Table group {$groupId} does not exist.", self::ERROR_GROUP_NOT_FOUND)
			);
		}

		if ($group->isSystem())
		{
			return $result->addError(
				new Error("Table group {$groupId} is a system one.", self::ERROR_NOT_CUSTOM_GROUP)
			);
		}

		if ($group->getName() === $name)
		{
			return $result->setData(['isChanged' => false]);
		}

		try
		{
			$updateResult = TableGroupTable::update($groupId, self::addAuthorOfChange(['NAME' => $name]));
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error($exception->getMessage()));
		}

		if (!$updateResult->isSuccess())
		{
			return $result->addErrors($updateResult->getErrors());
		}

		return $result->setData(['isChanged' => true]);
	}

	/**
	 * The person the write is attributed to.
	 *
	 * @return int|null Null when nobody is signed in: an agent and the console write no author rather
	 *                  than the user number zero.
	 */
	private static function getAuthorId(): ?int
	{
		$userId = (int)CurrentUser::get()->getId();

		return $userId > 0 ? $userId : null;
	}

	/**
	 * Names the person of the hit as the one who changed the group.
	 *
	 * A hit with nobody signed in adds nothing at all instead of writing an empty author: the change
	 * still happened, and the one who made the previous one is a better answer to "who changed this"
	 * than nobody.
	 *
	 * @param array $fields Fields of the update the author joins.
	 *
	 * @return array
	 */
	private static function addAuthorOfChange(array $fields): array
	{
		$authorId = self::getAuthorId();
		if ($authorId !== null)
		{
			$fields['UPDATED_BY_ID'] = $authorId;
		}

		return $fields;
	}

	/**
	 * Names the person of the hit as the one who changed the group the composition belongs to.
	 *
	 * Called from the paths that have just written the composition and only from them: a group whose
	 * composition stayed as it was is not a changed group, exactly as a rename to the name the group
	 * already has is not a change. A hit with nobody signed in leaves the previous author alone, and
	 * the modification date is moved by the entity itself.
	 *
	 * @param int $groupId
	 *
	 * @return Result
	 */
	private static function markGroupChanged(int $groupId): Result
	{
		$result = new Result();

		$fields = self::addAuthorOfChange([]);
		if (empty($fields))
		{
			return $result;
		}

		$updateResult = TableGroupTable::update($groupId, $fields);

		return $updateResult->isSuccess() ? $result : $result->addErrors($updateResult->getErrors());
	}

	/**
	 * Deletes a custom table group along with the rights granted on it.
	 *
	 * The deletion moves no table: a group is removed only while every table it holds is bound to
	 * some other group, otherwise the operation is refused and nothing in the database changes.
	 *
	 * @param int $groupId Group id.
	 *
	 * @return Result Error ERROR_TABLES_WOULD_MOVE speaks the refusal phrase and carries
	 *                ['tableCodes' => string[], 'groupId' => int].
	 */
	public static function deleteCustomGroup(int $groupId): Result
	{
		$result = self::checkGroupIsCustom($groupId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$deletionResult = self::writeGroupDeletion($groupId);
			if (!$deletionResult->isSuccess())
			{
				self::rollback($connection);

				return $deletionResult;
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollback($connection);

			return $result->addError(new Error($exception->getMessage()));
		}

		self::onGroupsChanged();

		return $result;
	}

	/**
	 * Deletes a custom table group inside a transaction the caller already holds.
	 *
	 * The refusal is the point of the whole operation, so the caller has to be one that rolls back
	 * by it: the composition of the groups is written in the same transaction just before, and the
	 * deletion is refused or allowed against that composition rather than against the one the
	 * request started with. A person moving a table out of a group and removing the group in one
	 * save is the ordinary case, not an exotic one.
	 *
	 * The caches are the caller's too: resetGroupCaches() once after its commit.
	 *
	 * @param int $groupId Group id.
	 *
	 * @return Result Error ERROR_TABLES_WOULD_MOVE speaks the refusal phrase and carries
	 *                ['tableCodes' => string[], 'groupId' => int].
	 */
	public static function deleteCustomGroupWithinTransaction(int $groupId): Result
	{
		$result = self::checkGroupIsCustom($groupId);

		return $result->isSuccess() ? self::writeGroupDeletion($groupId) : $result;
	}

	/**
	 * Tells whether the group can be deleted at all: it exists and it is not a system one.
	 *
	 * @param int $groupId
	 *
	 * @return Result
	 */
	private static function checkGroupIsCustom(int $groupId): Result
	{
		$result = new Result();

		if ($groupId <= 0)
		{
			return $result->addError(
				new Error('Table group id must be a positive integer.', self::ERROR_INVALID_GROUP)
			);
		}

		$group = TableGroupTable::getById($groupId)->fetchObject();

		if ($group === null)
		{
			return $result->addError(
				new Error("Table group {$groupId} does not exist.", self::ERROR_GROUP_NOT_FOUND)
			);
		}

		if ($group->isSystem())
		{
			return $result->addError(
				new Error("Table group {$groupId} is a system one.", self::ERROR_NOT_CUSTOM_GROUP)
			);
		}

		return $result;
	}

	/**
	 * Refuses the deletion or performs it, whoever holds the transaction it goes into.
	 *
	 * @param int $groupId
	 *
	 * @return Result
	 */
	private static function writeGroupDeletion(int $groupId): Result
	{
		$result = new Result();

		$blockingTableCodes = self::getTableCodesHeldOnlyBy($groupId);
		if (!empty($blockingTableCodes))
		{
			// the refusal is read by a person, so it speaks the phrase itself; the codes of the
			// blocking tables travel in the custom data, where the interface reads them
			return $result->addError(new UserFacingError(
				(string)Loc::getMessage('BICONNECTOR_TABLE_GROUP_DELETE_ERROR_TABLES_WOULD_MOVE'),
				self::ERROR_TABLES_WOULD_MOVE,
				[
					'tableCodes' => $blockingTableCodes,
					'groupId' => $groupId,
				],
			));
		}

		(new RolePermissionService())->deletePermissionsByTableGroup($groupId);

		$deleteResult = TableGroupTable::delete($groupId);

		return $deleteResult->isSuccess() ? $result : $result->addErrors($deleteResult->getErrors());
	}

	/**
	 * Deletes every custom group of the list inside a transaction the caller already holds.
	 *
	 * The traversal runs to the end here too - a person who removed several groups at once hears
	 * about each of them - but the consequence of a refusal is the caller's now: one transaction
	 * covers the whole list, so a refusal of any group leaves every other one in place as well.
	 *
	 * @param int[] $groupIds Ids to delete; a non-positive one is refused by the deletion itself.
	 *
	 * @return Result Every error keeps the data of its own code and carries ['groupId' => int].
	 */
	public static function deleteCustomGroupListWithinTransaction(array $groupIds): Result
	{
		$result = new Result();

		foreach ($groupIds as $groupId)
		{
			$groupId = (int)$groupId;

			$deleteResult = self::deleteCustomGroupWithinTransaction($groupId);
			if ($deleteResult->isSuccess())
			{
				continue;
			}

			foreach ($deleteResult->getErrors() as $error)
			{
				$result->addError(self::describeGroupOfError($error, $groupId));
			}
		}

		return $result;
	}

	/**
	 * Deletes every custom group of the list, whatever comes of the others.
	 *
	 * The traversal runs to the end instead of stopping at the first refusal: an administrator who
	 * removed several groups at once has to hear about each of them, not about the first. No
	 * transaction covers the traversal - every deletion is atomic by itself, and one transaction
	 * over them would mean another policy, "all or none". Hence the consequence: a group already
	 * deleted stays deleted when a later one is refused.
	 *
	 * @param int[] $groupIds Ids to delete; a non-positive one is refused by the deletion itself.
	 *
	 * @return Result Every error keeps the data of its own code and carries ['groupId' => int].
	 */
	public static function deleteCustomGroupList(array $groupIds): Result
	{
		$result = new Result();

		foreach ($groupIds as $groupId)
		{
			$groupId = (int)$groupId;

			$deleteResult = self::deleteCustomGroup($groupId);
			if ($deleteResult->isSuccess())
			{
				continue;
			}

			foreach ($deleteResult->getErrors() as $error)
			{
				$result->addError(self::describeGroupOfError($error, $groupId));
			}
		}

		return $result;
	}

	/**
	 * Names the group the error is about.
	 *
	 * An error is immutable, so the data is carried by a new one: the code and the custom data of
	 * the refusal are kept as they were, and the group id joins them, because the list answers for
	 * several groups at once and the reader of the answer has no other way to tell them apart.
	 * An error that names its group already goes on untouched - rebuilding it would turn a refusal
	 * meant for a person into a plain one.
	 *
	 * @param Error $error
	 * @param int $groupId
	 *
	 * @return Error
	 */
	private static function describeGroupOfError(Error $error, int $groupId): Error
	{
		$customData = $error->getCustomData();
		$customData = is_array($customData) ? $customData : [];
		if (isset($customData['groupId']))
		{
			return $error;
		}

		$customData['groupId'] = $groupId;

		return new Error($error->getMessage(), $error->getCode(), $customData);
	}

	/**
	 * Returns the table codes the group is the last binding of.
	 *
	 * The composition of every group, the user tables one included, is stored as binding rows. A table
	 * that loses its last binding therefore belongs to no group at all, and nothing grants access to
	 * it any more - that silent refusal is what the deletion must not cause.
	 *
	 * @param int $groupId
	 *
	 * @return string[]
	 */
	private static function getTableCodesHeldOnlyBy(int $groupId): array
	{
		$tableCodes = self::getBoundTableCodes($groupId);
		if (empty($tableCodes))
		{
			return [];
		}

		$rows = TableGroupBindingTable::getList([
			'select' => ['TABLE_CODE'],
			'filter' => [
				'@TABLE_CODE' => $tableCodes,
				'!=GROUP_ID' => $groupId,
			],
		])->fetchAll();

		return array_values(
			array_diff($tableCodes, self::normalizeCodes(array_column($rows, 'TABLE_CODE')))
		);
	}

	/**
	 * Returns a code no group has yet.
	 *
	 * The number of attempts is limited on purpose: a code taken ten times in a row means something
	 * other than an unlucky draw, and an endless retry would hide it.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When every attempt hit an existing code.
	 */
	private static function generateGroupCode(): string
	{
		for ($attempt = 0; $attempt < self::CODE_GENERATION_ATTEMPTS; $attempt++)
		{
			$code = mb_substr(uniqid('CUSTOM_'), 0, self::CODE_LENGTH_LIMIT);

			$existingGroup = TableGroupTable::getList([
				'select' => ['ID'],
				'filter' => ['=CODE' => $code],
				'limit' => 1,
			])->fetch();

			if (!$existingGroup)
			{
				return $code;
			}
		}

		throw new \RuntimeException('Cannot generate a free table group code.');
	}

	/**
	 * Creates a custom group or renames an existing one and replaces its composition, all or nothing.
	 *
	 * The name and the composition are written in one transaction: a composition refused because it
	 * would leave a table of the catalog in no group takes the new name and the new group down with
	 * it. The name of a system group belongs to the product and is left as it is, while its
	 * composition is written the way the composition of any other group is.
	 *
	 * @param int|null $groupId Group id; null creates a custom group.
	 * @param string $name Name of the group; ignored for a system group.
	 * @param string[] $tableCodes Table codes the group must consist of.
	 *
	 * @return Result Data: ['id' => int]. Error ERROR_TABLES_WITHOUT_GROUP speaks the refusal phrase
	 *                and carries ['tableCodes' => string[]].
	 */
	public static function saveGroup(?int $groupId, string $name, array $tableCodes): Result
	{
		$result = new Result();

		if ($groupId === null)
		{
			$codesToAdd = self::normalizeCodes($tableCodes);
			$codesToDelete = [];
		}
		else
		{
			$changeResult = self::planBindingReplacement($groupId, $tableCodes);
			if (!$changeResult->isSuccess())
			{
				return $result->addErrors($changeResult->getErrors());
			}

			[$codesToAdd, $codesToDelete] = $changeResult->getData()['change'];
		}

		// read before the transaction for the reason replaceGroupBindings() gives
		$catalogTitles = empty($codesToDelete) ? [] : TableCatalogService::getTableTitles();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$groupResult = $groupId === null
				? self::writeCustomGroup($name)
				: self::writeNameOfSavedGroup($groupId, $name);
			if (!$groupResult->isSuccess())
			{
				self::rollback($connection);

				return $result->addErrors($groupResult->getErrors());
			}

			$isCreated = $groupId === null;
			$groupId ??= (int)$groupResult->getData()['id'];
			$isChanged = $isCreated || !empty($groupResult->getData()['isChanged']);

			if (!empty($codesToAdd) || !empty($codesToDelete))
			{
				$isChanged = true;
				$writeResult = self::writeBindingReplacement($groupId, $codesToAdd, $codesToDelete);
				if (!$writeResult->isSuccess())
				{
					self::rollback($connection);

					return $result->addErrors($writeResult->getErrors());
				}
			}

			$coverageResult = self::checkCodesAreStillHeld($codesToDelete, $catalogTitles);
			if (!$coverageResult->isSuccess())
			{
				self::rollback($connection);

				return $result->addErrors($coverageResult->getErrors());
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollback($connection);

			return $result->addError(new Error($exception->getMessage()));
		}

		if ($isChanged)
		{
			self::onGroupsChanged();
		}

		return $result->setData(['id' => $groupId]);
	}

	/**
	 * Renames the group of saveGroup() unless it is a system one.
	 *
	 * @param int $groupId
	 * @param string $name
	 *
	 * @return Result
	 */
	private static function writeNameOfSavedGroup(int $groupId, string $name): Result
	{
		$group = TableGroupTable::getById($groupId)->fetchObject();
		if ($group === null)
		{
			return (new Result())->addError(
				new Error("Table group {$groupId} does not exist.", self::ERROR_GROUP_NOT_FOUND),
			);
		}

		return $group->isSystem() ? new Result() : self::writeGroupName($groupId, $name);
	}

	/**
	 * Replaces the whole binding set of the group with the given table codes.
	 *
	 * The two writes are covered by a transaction of this method, so a caller that already holds one
	 * takes replaceGroupBindingsWithinTransaction() instead.
	 *
	 * A composition that really changed names the person of the hit as the one who changed the group:
	 * moving a table into a group and out of it is a change of the group, the same as a rename.
	 *
	 * A composition that would leave a table of the catalog in no group at all is refused, and
	 * nothing of the save is written. A table that already belonged to no group is none of this
	 * save's doing and is left as it was found.
	 *
	 * @param int $groupId Group id.
	 * @param string[] $tableCodes Table codes the group must consist of.
	 *
	 * @return Result Data: ['isChanged' => bool]. Error ERROR_TABLES_WITHOUT_GROUP speaks the refusal
	 *                phrase and carries ['tableCodes' => string[]].
	 */
	public static function replaceGroupBindings(int $groupId, array $tableCodes): Result
	{
		$result = new Result();

		$changeResult = self::planBindingReplacement($groupId, $tableCodes);
		if (!$changeResult->isSuccess())
		{
			return $result->addErrors($changeResult->getErrors());
		}

		[$codesToAdd, $codesToDelete] = $changeResult->getData()['change'];
		if (empty($codesToAdd) && empty($codesToDelete))
		{
			return $result->setData(['isChanged' => false]);
		}

		// The catalog is read before the transaction opens: its names are the same whoever holds the
		// rows, and building it sends an event to every module - the bindings of the group would stay
		// locked for the whole of that walk.
		$catalogTitles = empty($codesToDelete) ? [] : TableCatalogService::getTableTitles();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$writeResult = self::writeBindingReplacement($groupId, $codesToAdd, $codesToDelete);
			if (!$writeResult->isSuccess())
			{
				self::rollback($connection);

				return $result->addErrors($writeResult->getErrors());
			}

			// A table taken out of its last group would be held by nobody, and that is the very refusal
			// the save of the rights matrix speaks. Only the codes this save takes away can lose their
			// last binding, so the check inside the transaction asks about those alone.
			$coverageResult = self::checkCodesAreStillHeld($codesToDelete, $catalogTitles);
			if (!$coverageResult->isSuccess())
			{
				self::rollback($connection);

				return $result->addErrors($coverageResult->getErrors());
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollback($connection);

			return $result->addError(new Error($exception->getMessage()));
		}

		self::onGroupsChanged();

		return $result->setData(['isChanged' => true]);
	}

	/**
	 * Replaces the binding set of the group inside a transaction the caller already holds.
	 *
	 * A transaction of its own would be a nested one, and nesting is a savepoint on MySQL and
	 * PostgreSQL only: on Oracle and MSSQL an inner commit commits the outer work too, and a later
	 * failure of the caller would no longer roll the composition back. Hence the writes here go
	 * straight into the transaction of the caller, and so does a failure of theirs - an exception
	 * is not caught, an error is returned for the caller to roll back by.
	 *
	 * The caches are the caller's too: resetGroupCaches() once after its commit. Emptying them here
	 * would empty them over a write that is not committed yet and may still be rolled back.
	 *
	 * @param int $groupId Group id.
	 * @param string[] $tableCodes Table codes the group must consist of.
	 *
	 * @return Result Data: ['isChanged' => bool].
	 */
	public static function replaceGroupBindingsWithinTransaction(int $groupId, array $tableCodes): Result
	{
		$result = new Result();

		$changeResult = self::planBindingReplacement($groupId, $tableCodes);
		if (!$changeResult->isSuccess())
		{
			return $result->addErrors($changeResult->getErrors());
		}

		[$codesToAdd, $codesToDelete] = $changeResult->getData()['change'];
		if (empty($codesToAdd) && empty($codesToDelete))
		{
			return $result->setData(['isChanged' => false]);
		}

		$writeResult = self::writeBindingReplacement($groupId, $codesToAdd, $codesToDelete);
		if (!$writeResult->isSuccess())
		{
			return $result->addErrors($writeResult->getErrors());
		}

		return $result->setData(['isChanged' => true]);
	}

	/**
	 * Tells what the replacement has to write.
	 *
	 * @param int $groupId
	 * @param string[] $tableCodes
	 *
	 * @return Result Data: ['change' => [string[] $codesToAdd, string[] $codesToDelete]].
	 */
	private static function planBindingReplacement(int $groupId, array $tableCodes): Result
	{
		$result = new Result();

		if ($groupId <= 0)
		{
			return $result->addError(
				new Error('Table group id must be a positive integer.', self::ERROR_INVALID_GROUP)
			);
		}

		$tableCodes = self::normalizeCodes($tableCodes);
		$existingCodes = self::getBoundTableCodes($groupId);

		return $result->setData(['change' => [
			array_values(array_diff($tableCodes, $existingCodes)),
			array_values(array_diff($existingCodes, $tableCodes)),
		]]);
	}

	/**
	 * Writes the replacement, whoever holds the transaction it goes into.
	 *
	 * @param int $groupId
	 * @param string[] $codesToAdd
	 * @param string[] $codesToDelete
	 *
	 * @return Result
	 */
	private static function writeBindingReplacement(int $groupId, array $codesToAdd, array $codesToDelete): Result
	{
		$result = new Result();

		if (!empty($codesToDelete))
		{
			TableGroupBindingTable::deleteByFilter([
				'=GROUP_ID' => $groupId,
				'@TABLE_CODE' => $codesToDelete,
			]);
		}

		if (!empty($codesToAdd))
		{
			$rows = array_map(
				static fn(string $tableCode): array => [
					'GROUP_ID' => $groupId,
					'TABLE_CODE' => $tableCode,
				],
				$codesToAdd,
			);

			// the second argument is what keeps the rows in one INSERT: with an autoincrement key and
			// events asked for, the core falls back to a query per row
			$addResult = TableGroupBindingTable::addMulti($rows, true);
			if (!$addResult->isSuccess())
			{
				return $result->addErrors($addResult->getErrors());
			}
		}

		// The composition is part of the group, so the one who moved a table into it changed the group
		// itself. The mark goes into the transaction of the composition: a rolled back change of the
		// tables must not leave the group naming its author.
		return self::markGroupChanged($groupId);
	}

	/**
	 * Takes the given table codes out of every group they are bound to.
	 *
	 * It is the counterpart of the writes above for a table that has ceased to exist: the entity
	 * behind it is gone, so there is nothing left to hold rights over, and a binding kept after that
	 * would hand the groups of the deleted table to the next table of the same name.
	 *
	 * Unlike the moves of a living table, this one asks nobody: a deletion is not a change of the
	 * composition somebody may have decided on by hand, and the bindings a person has written for
	 * that code are just as stale as the ones a run has written.
	 *
	 * @param string[] $tableCodes Codes of the tables that no longer exist.
	 *
	 * @return Result Data: ['deletedCodes' => string[]] - the codes that had a binding.
	 */
	public static function deleteTableBindings(array $tableCodes): Result
	{
		$result = new Result();

		$tableCodes = self::normalizeCodes($tableCodes);
		if (empty($tableCodes))
		{
			return $result->setData(['deletedCodes' => []]);
		}

		$boundCodes = self::getBoundCodesOf($tableCodes);
		if (empty($boundCodes))
		{
			// Nothing was written, so nothing is reset either: the deletion of a table nobody bound is
			// the common case on a portal whose composition was arranged before the table appeared.
			return $result->setData(['deletedCodes' => []]);
		}

		try
		{
			TableGroupBindingTable::deleteByFilter(['@TABLE_CODE' => $boundCodes]);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error($exception->getMessage()));
		}

		self::onGroupsChanged();

		return $result->setData(['deletedCodes' => $boundCodes]);
	}

	/**
	 * Takes the given table codes out of one group, leaving whatever other groups hold them.
	 *
	 * The counterpart of addGroupBindings() for the caller that answers for one group only: the
	 * deletion of an external dataset named after a table of another group takes back the binding
	 * the dataset itself was given and leaves the rest alone.
	 *
	 * @param int $groupId Group id.
	 * @param string[] $tableCodes Codes to take out of that group.
	 *
	 * @return Result Data: ['deletedCodes' => string[]] - the codes that were bound to it.
	 */
	public static function deleteGroupBindings(int $groupId, array $tableCodes): Result
	{
		$result = new Result();

		if ($groupId <= 0)
		{
			return $result->addError(
				new Error('Table group id must be a positive integer.', self::ERROR_INVALID_GROUP)
			);
		}

		$codesToDelete = array_values(
			array_intersect(self::normalizeCodes($tableCodes), self::getBoundTableCodes($groupId))
		);
		if (empty($codesToDelete))
		{
			return $result->setData(['deletedCodes' => []]);
		}

		try
		{
			TableGroupBindingTable::deleteByFilter([
				'=GROUP_ID' => $groupId,
				'@TABLE_CODE' => $codesToDelete,
			]);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error($exception->getMessage()));
		}

		self::onGroupsChanged();

		return $result->setData(['deletedCodes' => $codesToDelete]);
	}

	/**
	 * @param string[] $tableCodes
	 *
	 * @return string[] The given codes that are bound to any group.
	 */
	private static function getBoundCodesOf(array $tableCodes): array
	{
		$rows = TableGroupBindingTable::getList([
			'select' => ['TABLE_CODE'],
			'filter' => ['@TABLE_CODE' => $tableCodes],
			'group' => ['TABLE_CODE'],
		])->fetchAll();

		return array_column($rows, 'TABLE_CODE');
	}

	/**
	 * Adds the given table codes to the group, keeping every binding it already has.
	 *
	 * Unlike replaceGroupBindings() the operation never deletes. It is meant for the caller that
	 * declares one new table, where the whole composition of the group is not its business: dropping
	 * a binding written by someone else would be a silent loss of membership.
	 *
	 * The author of the group is left alone for the same reason: this path belongs to the installer of
	 * the composition and to the handler of a new dataset, and neither of them is a person changing
	 * the group.
	 *
	 * @param int $groupId Group id.
	 * @param string[] $tableCodes Table codes the group must contain.
	 *
	 * @return Result Data: ['addedCodes' => string[]].
	 */
	public static function addGroupBindings(int $groupId, array $tableCodes): Result
	{
		$result = new Result();

		if ($groupId <= 0)
		{
			return $result->addError(
				new Error('Table group id must be a positive integer.', self::ERROR_INVALID_GROUP)
			);
		}

		$codesToAdd = array_values(
			array_diff(self::normalizeCodes($tableCodes), self::getBoundTableCodes($groupId))
		);

		if (empty($codesToAdd))
		{
			return $result->setData(['addedCodes' => []]);
		}

		$rows = array_map(
			static fn(string $tableCode): array => [
				'GROUP_ID' => $groupId,
				'TABLE_CODE' => $tableCode,
			],
			$codesToAdd,
		);

		try
		{
			// the events are suppressed, see writeBindingReplacement()
			$addResult = TableGroupBindingTable::addMulti($rows, true);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error($exception->getMessage()));
		}

		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		self::onGroupsChanged();

		return $result->setData(['addedCodes' => $codesToAdd]);
	}

	/**
	 * Brings the bindings of the table to exactly the given set of groups inside a transaction the caller
	 * already holds.
	 *
	 * Serves both the creation of a table and the change of its groups: a binding outside the set - the
	 * leftover of a deleted namesake or a group the table leaves - is dropped, a missing one is written,
	 * and a binding of a group that stays is not touched. A code the system groups speak for is refused
	 * without a single write: it is declared by SystemTableGroupMap, published by the catalog or not, or
	 * it is bound to a system group other than the user tables one while no external dataset carries it.
	 * Taking it would let a person rewrite a composition set by the map or by an administrator. A live
	 * external dataset in a system group was put there by a person when it was created, so its groups
	 * may be changed.
	 *
	 * The transaction and the caches are the caller's, as in replaceGroupBindingsWithinTransaction().
	 *
	 * @param string $tableCode Table code, the name of the dataset.
	 * @param int[] $groupIds Ids of the groups the table has to end up in; repeats are collapsed.
	 *
	 * @return Result Data: ['addedGroupIds' => int[], 'removedGroupIds' => int[]].
	 */
	public static function setTableGroupsWithinTransaction(string $tableCode, array $groupIds): Result
	{
		$result = new Result();

		$groupIds = self::normalizeGroupIds($groupIds);
		if ($groupIds === null || $tableCode === '')
		{
			return $result->addError(
				new Error(
					'Table group ids must be a non-empty set of positive integers and table code must not be empty.',
					self::ERROR_INVALID_GROUP,
				)
			);
		}

		$existingGroupIds = array_map(
			'intval',
			array_column(
				TableGroupTable::getList([
					'select' => ['ID'],
					'filter' => ['@ID' => $groupIds],
				])->fetchAll(),
				'ID',
			),
		);
		$missingGroupIds = array_diff($groupIds, $existingGroupIds);
		if (!empty($missingGroupIds))
		{
			$missingList = implode(', ', $missingGroupIds);

			return $result->addError(
				new Error("Table groups {$missingList} do not exist.", self::ERROR_GROUP_NOT_FOUND)
			);
		}

		if (self::isTableCodeReserved($tableCode))
		{
			return $result->addError(
				new Error(
					"Table code {$tableCode} belongs to a system table group.",
					self::ERROR_TABLE_CODE_RESERVED,
				)
			);
		}

		$boundGroupIds = self::getGroupIdsOfTable($tableCode);
		$groupIdsToAdd = array_values(array_diff($groupIds, $boundGroupIds));
		$groupIdsToRemove = array_values(array_diff($boundGroupIds, $groupIds));

		if (!empty($groupIdsToRemove))
		{
			TableGroupBindingTable::deleteByFilter([
				'=TABLE_CODE' => $tableCode,
				'@GROUP_ID' => $groupIdsToRemove,
			]);
		}

		if (!empty($groupIdsToAdd))
		{
			$rows = array_map(
				static fn(int $groupId): array => [
					'GROUP_ID' => $groupId,
					'TABLE_CODE' => $tableCode,
				],
				$groupIdsToAdd,
			);

			// the events are suppressed, see writeBindingReplacement()
			$addResult = TableGroupBindingTable::addMulti($rows, true);
			if (!$addResult->isSuccess())
			{
				return $result->addErrors($addResult->getErrors());
			}
		}

		return $result->setData([
			'addedGroupIds' => $groupIdsToAdd,
			'removedGroupIds' => $groupIdsToRemove,
		]);
	}

	/**
	 * @param array $groupIds
	 *
	 * @return int[]|null Unique positive ids, or null when the set is empty or holds a non-positive element.
	 */
	private static function normalizeGroupIds(array $groupIds): ?array
	{
		$normalized = [];
		foreach ($groupIds as $groupId)
		{
			$groupId = (int)$groupId;
			if ($groupId <= 0)
			{
				return null;
			}

			$normalized[$groupId] = $groupId;
		}

		return empty($normalized) ? null : array_values($normalized);
	}

	/**
	 * @param string $tableCode
	 *
	 * @return int[]
	 */
	private static function getGroupIdsOfTable(string $tableCode): array
	{
		$rows = TableGroupBindingTable::getList([
			'select' => ['GROUP_ID'],
			'filter' => ['=TABLE_CODE' => $tableCode],
		])->fetchAll();

		return array_map('intval', array_column($rows, 'GROUP_ID'));
	}

	/**
	 * Reads the bindings by a query of its own: the caller may have written them in the same transaction.
	 *
	 * @param string $tableCode
	 *
	 * @return bool
	 */
	private static function isTableCodeReserved(string $tableCode): bool
	{
		if (self::isDeclaredInMap($tableCode))
		{
			return true;
		}

		// On creation the binding is written before the row of the dataset, so a live one means a change
		if (ExternalDatasetTable::getCount(['=NAME' => $tableCode]) > 0)
		{
			return false;
		}

		$systemBinding = TableGroupBindingTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=TABLE_CODE' => $tableCode,
				'=GROUP.TYPE' => TableGroupTable::GROUP_TYPE_SYSTEM,
				'!=GROUP.CODE' => SystemTableGroupMap::getUserTablesGroupCode(),
			],
			'limit' => 1,
		])->fetch();

		return $systemBinding !== false;
	}

	private static function isDeclaredInMap(string $tableCode): bool
	{
		return SystemTableGroupMap::isReservedCode($tableCode);
	}

	/**
	 * Rolls the own transaction back, exactly once per failure path.
	 *
	 * The service may be called from within an already open transaction: the installer runs under
	 * the one of the agent. A rollback of a nested transaction returns to the savepoint and only
	 * then reports the nesting itself as an exception - the changes are undone by then and the
	 * outer transaction stays alive, so that report must not leave the boundary.
	 *
	 * @param Connection $connection
	 *
	 * @return void
	 */
	private static function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
	}

	/**
	 * @param int $groupId
	 *
	 * @return string[]
	 */
	private static function getBoundTableCodes(int $groupId): array
	{
		$rows = TableGroupBindingTable::getList([
			'select' => ['TABLE_CODE'],
			'filter' => ['=GROUP_ID' => $groupId],
		])->fetchAll();

		return self::normalizeCodes(array_column($rows, 'TABLE_CODE'));
	}

	/**
	 * @param string[] $tableCodes
	 *
	 * @return string[]
	 */
	private static function normalizeCodes(array $tableCodes): array
	{
		$normalizedCodes = [];
		foreach ($tableCodes as $tableCode)
		{
			$tableCode = (string)$tableCode;
			if ($tableCode !== '')
			{
				$normalizedCodes[$tableCode] = $tableCode;
			}
		}

		return array_values($normalizedCodes);
	}

	/**
	 * Empties the caches of the groups for a caller that wrote them inside its own transaction.
	 *
	 * The methods that hold their own transaction do this themselves; the ones that write into the
	 * transaction of the caller cannot, because the write is not committed yet. Such a caller resets
	 * once, after its commit, and for the whole batch it wrote rather than for every group of it.
	 */
	public static function resetGroupCaches(): void
	{
		self::onGroupsChanged();
	}

	/**
	 * Drops what the readers of the axis keep of the group list, and tells the consumers of the set.
	 *
	 * The reset belongs to the write boundary, not to its callers, and it is wider than the
	 * membership snapshot alone: the permission list of the matrix and the rules are built over the
	 * same rows, so a group written without it stays missing from the matrix for the rest of the hit.
	 * It is called on the paths that wrote something, after the transaction is committed - with one
	 * exception: the unbinding of a deleted entity runs inside the transaction of whoever deletes it.
	 * That is safe, because nothing here reads a row: the caches are emptied, and the mark of the
	 * delivery is an option written in the same transaction, so it goes away with a rollback together
	 * with the change it announces. The owner of the transaction resets once more after its commit.
	 *
	 * The notification of the consumers stands here for that last reason and nowhere else: every
	 * caller of this point is past its commit, so "the delivery is never inside a transaction" is a
	 * property of one point instead of a line repeated at every path - and a line repeated at every
	 * path is lost, or moved inside somebody's transaction, by the first merge that rewrites one of
	 * them. Creating and renaming a group changes nobody's set and asks anyway: one mark per action
	 * of an administrator, and the memo of the hit folds a series of them into one.
	 *
	 * What this point does not answer for: a write of the matrix itself and a deletion of a role
	 * touch no group and reach no caller here, so they ask for their own reset where they happen.
	 */
	private static function onGroupsChanged(): void
	{
		PermissionDictionary::clearGroupPermissions();
		TableVisibilityService::clearCache();

		PermissionCacheResetNotifier::notifyChanged();
	}

	/**
	 * Refuses the save while a table of the catalog is held by no group at all.
	 *
	 * Called from inside the transaction of the save and reads the bindings by a query of its own:
	 * the check has to see the rows the very same transaction has just written.
	 *
	 * An empty catalog is no refusal: a catalog that failed to build must not take the save of the
	 * rights away.
	 *
	 * @return Result Error ERROR_TABLES_WITHOUT_GROUP speaks the refusal phrase and carries
	 *                ['tableCodes' => string[]] - every code of them, however many names the text
	 *                itself shows.
	 */
	public static function check(): Result
	{
		$result = new Result();

		$tables = self::getTablesWithoutGroup();
		if (empty($tables))
		{
			return $result;
		}

		return $result->addError(self::buildTablesWithoutGroupError($tables));
	}

	/**
	 * Refuses the composition that leaves one of the codes it takes away in no group at all.
	 *
	 * The whole axis is not asked about here: a code no group held before this save is left as it was
	 * found, and only a code this save takes out of a group can lose its last binding. That is one
	 * query over those codes instead of a read of every binding row of the portal, and it runs inside
	 * the transaction because it has to see what the transaction has just written. The catalog stands
	 * outside the transaction and is handed over already read.
	 *
	 * @param string[] $codesToDelete Codes the save takes out of the group.
	 * @param array<string, string> $catalogTitles Code of a table of the catalog to its name.
	 *
	 * @return Result Error ERROR_TABLES_WITHOUT_GROUP, the same one check() speaks.
	 */
	private static function checkCodesAreStillHeld(array $codesToDelete, array $catalogTitles): Result
	{
		$result = new Result();

		$codesOfCatalog = array_values(array_intersect($codesToDelete, array_keys($catalogTitles)));
		if (empty($codesOfCatalog))
		{
			return $result;
		}

		$rows = TableGroupBindingTable::getList([
			'select' => ['TABLE_CODE'],
			'filter' => ['@TABLE_CODE' => $codesOfCatalog],
		])->fetchAll();
		$heldCodes = array_fill_keys(self::normalizeCodes(array_column($rows, 'TABLE_CODE')), true);

		$tables = [];
		foreach ($codesOfCatalog as $tableCode)
		{
			if (!isset($heldCodes[$tableCode]))
			{
				$tables[$tableCode] = $catalogTitles[$tableCode];
			}
		}

		if (empty($tables))
		{
			return $result;
		}

		asort($tables, SORT_STRING);

		return $result->addError(self::buildTablesWithoutGroupError($tables));
	}

	/**
	 * The refusal both checks of the coverage speak.
	 *
	 * The refusal is read by a person, so it speaks the phrase itself; the codes of the tables travel
	 * in the custom data, where the interface reads them.
	 *
	 * @param array<string, string> $tables Code of a table held by no group to its name, ordered by
	 *                                      the name.
	 *
	 * @return UserFacingError
	 */
	private static function buildTablesWithoutGroupError(array $tables): UserFacingError
	{
		return new UserFacingError(
			(string)Loc::getMessage(
				self::getRefusalPhraseId(count($tables)),
				['#TABLES#' => self::buildTablesText($tables)],
			),
			self::ERROR_TABLES_WITHOUT_GROUP,
			['tableCodes' => array_keys($tables)],
		);
	}

	/**
	 * Phrase the refusal of the given count of tables is spoken by.
	 *
	 * The difference the text stands on is one table against several, and it is not the three forms
	 * of the Russian plural: the text shows a list of names and no number at all, so a plural form
	 * has nothing to agree with - by the forms twenty one tables would take the one of a single table.
	 *
	 * @param int $tableCount Tables of the catalog no group holds, all of them and not the shown ones.
	 *
	 * @return string
	 */
	private static function getRefusalPhraseId(int $tableCount): string
	{
		return $tableCount === 1
			? 'BICONNECTOR_ACCESS_TABLES_WITHOUT_GROUP_ONE'
			: 'BICONNECTOR_ACCESS_TABLES_WITHOUT_GROUP_MANY';
	}

	/**
	 * Tables of the catalog no group holds, by the name each of them is called by.
	 *
	 * @return array<string, string> Code of the table to its name, ordered by the name.
	 */
	private static function getTablesWithoutGroup(): array
	{
		$tables = array_diff_key(TableCatalogService::getTableTitles(), self::getTableCodesHeldBySomeGroup());
		asort($tables, SORT_STRING);

		return $tables;
	}

	/**
	 * @return array<string, true> Code of every table some group holds, the whole axis at once.
	 */
	private static function getTableCodesHeldBySomeGroup(): array
	{
		$rows = TableGroupBindingTable::getList(['select' => ['TABLE_CODE']])->fetchAll();

		return array_fill_keys(array_column($rows, 'TABLE_CODE'), true);
	}

	/**
	 * List of the tables for the refusal to show: the name and the code of each, and the count of
	 * the ones it leaves out as the last item.
	 *
	 * The text is read by a person, so the enumeration is bound and the rest is counted; the phrase
	 * of the refusal is chosen by the whole count of the tables all the same.
	 *
	 * The text is HTML, and both clients render it so. The name of a table is free text of whoever
	 * declared the dataset, so the name and the code are escaped here, after the tables have been
	 * ordered: escaped first, a name carrying & or " would be ordered by the sequence it turned into.
	 *
	 * @param array<string, string> $tables Code of the table to its name, ordered by the name.
	 *
	 * @return string
	 */
	private static function buildTablesText(array $tables): string
	{
		$items = [];
		foreach (array_slice($tables, 0, self::MAX_NAMES_IN_REFUSAL, true) as $tableCode => $tableName)
		{
			$items[] = (string)Loc::getMessage(
				'BICONNECTOR_ACCESS_TABLES_WITHOUT_GROUP_ITEM',
				[
					'#TABLE_NAME#' => htmlspecialcharsbx((string)$tableName),
					'#TABLE_CODE#' => htmlspecialcharsbx((string)$tableCode),
				],
			);
		}

		$countLeftOut = count($tables) - self::MAX_NAMES_IN_REFUSAL;
		if ($countLeftOut > 0)
		{
			$items[] = (string)Loc::getMessage(
				'BICONNECTOR_ACCESS_TABLES_WITHOUT_GROUP_MORE',
				['#COUNT#' => $countLeftOut],
			);
		}

		return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
	}

	/**
	 * Returns every table group in the order the rights list shows them.
	 *
	 * The id of a group is its id in the permission matrix, so the popup and the row of the matrix
	 * name the same group the same way.
	 *
	 * @return array<int, array{id: string, name: string, type: string, tableCodes: string[]}>
	 */
	public static function getGroups(): array
	{
		$result = [];

		$groups = TableGroupTable::getList([
			'select' => ['ID', 'NAME', 'TYPE'],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
			'cache' => ['ttl' => self::POPUP_CACHE_TTL],
		]);
		while ($group = $groups->fetchObject())
		{
			$result[] = [
				'id' => PermissionDictionary::getTableGroupPermissionId($group->getId()),
				'name' => $group->getName(),
				'type' => $group->getType(),
				'tableCodes' => TableGroupMembershipService::getTableCodesByGroupId($group->getId()),
			];
		}

		return $result;
	}

	/**
	 * Returns the whole table catalog for the selector of the popup.
	 *
	 * @return array<int, array{code: string, name: string, type: string}>
	 */
	public static function getTables(): array
	{
		$result = [];
		foreach (TableCatalogService::getTableTitles() as $tableCode => $tableTitle)
		{
			// The kind of the table picks its icon, the same pair of icons the dashboard list uses.
			$isUserTable = TableCatalogService::isUserTable($tableCode);

			$result[] = [
				'code' => $tableCode,
				'name' => $tableTitle,
				'type' => $isUserTable ? self::TABLE_TYPE_CUSTOM : self::TABLE_TYPE_SYSTEM,
			];
		}

		// The selector shows the catalog as a flat list, so it is sorted by what the person reads.
		usort($result, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

		return $result;
	}
}
