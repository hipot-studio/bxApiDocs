<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\SharedSignature;

use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Internals\Entity\SharedSignature;
use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class SharedSignatureService
{
	public const ERROR_NOT_FOUND = 'NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'SHARED_SIGNATURE_ACCESS_DENIED';
	public const ERROR_INVALID_SCOPE = 'SIGNATURE_INVALID_SCOPE';
	public const ERROR_ASSIGNMENTS_REQUIRED_FOR_SCOPE_CHANGE = 'SIGNATURE_ASSIGNMENTS_REQUIRED_FOR_SCOPE_CHANGE';
	public const ERROR_EMPTY_BODY = 'SIGNATURE_EMPTY_BODY';

	private const INTERFACE_OPTION_NAME = 'shared_signature_enabled';

	/**
	 * Returns signatures with their assignments, newest first.
	 * Assignments of the whole page are fetched with a single query.
	 *
	 * @param int|null $limit  Page size; null returns every signature.
	 * @param int|null $offset Rows to skip; ignored without a limit (SQL forbids a bare offset).
	 * @param array $filter    Extra ORM filter merged into the query (e.g. owner and scope).
	 * @return array<array{signature: SharedSignature, assignments: array}>
	 */
	public function getList(?int $limit = null, ?int $offset = null, array $filter = []): array
	{
		$parameters = [
			'order' => ['ID' => 'DESC'],
		];

		if (!empty($filter))
		{
			$parameters['filter'] = $filter;
		}

		if ($limit !== null && $limit > 0)
		{
			$parameters['limit'] = $limit;

			if ($offset !== null && $offset > 0)
			{
				$parameters['offset'] = $offset;
			}
		}

		$rows = [];
		$result = SharedSignatureTable::getList($parameters);
		while ($row = $result->fetchObject())
		{
			$rows[] = $row;
		}

		$assignments = $this->getAssignmentsForSignatures(
			array_map(static fn(SharedSignature $row): int => (int)$row->getId(), $rows),
		);

		$signatures = [];
		foreach ($rows as $row)
		{
			$signatures[] = [
				'signature' => $row,
				'assignments' => $assignments[(int)$row->getId()] ?? [],
			];
		}

		return $signatures;
	}

	/**
	 * Returns the total number of signatures matching the filter (for grid pagination).
	 */
	public function getTotalCount(array $filter = []): int
	{
		return (int)SharedSignatureTable::getCount($filter);
	}

	/**
	 * Returns signatures owned by the given user, optionally narrowed to one scope.
	 *
	 * @return array<array{signature: SharedSignature, assignments: array}>
	 */
	public function getListByOwner(int $ownerId, ?string $scope = null, ?int $limit = null, ?int $offset = null): array
	{
		return $this->getList($limit, $offset, self::buildOwnerFilter($ownerId, $scope));
	}

	/**
	 * Returns the number of signatures owned by the given user.
	 */
	public function getTotalCountByOwner(int $ownerId, ?string $scope = null): int
	{
		return $this->getTotalCount(self::buildOwnerFilter($ownerId, $scope));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function buildOwnerFilter(int $ownerId, ?string $scope): array
	{
		$filter = ['=OWNER_ID' => $ownerId];

		if ($scope !== null)
		{
			$filter['=SCOPE'] = $scope;
		}

		return $filter;
	}

	/**
	 * Builds the filter describing everything the given user is allowed to see: his own
	 * signatures always, plus the shared ones when he may manage them.
	 *
	 * @param string|null $scope Narrows the result to one scope; an unavailable scope yields no rows.
	 * @return array<mixed>
	 */
	public function buildVisibilityFilter(int $userId, ?string $scope = null): array
	{
		$ownScope = self::buildOwnerFilter($userId, SharedSignatureTable::SCOPE_OWNER);
		$sharedScope = ['=SCOPE' => SharedSignatureTable::SCOPE_SHARED];

		if ($scope === SharedSignatureTable::SCOPE_OWNER)
		{
			return $ownScope;
		}

		if ($scope === SharedSignatureTable::SCOPE_SHARED)
		{
			// An impossible condition is the honest answer: the user sees no shared signatures.
			return self::canManageSharedScope() ? $sharedScope : ['=ID' => 0];
		}

		return self::canManageSharedScope()
			? ['LOGIC' => 'OR', $ownScope, $sharedScope]
			: $ownScope
		;
	}

	/**
	 * Builds the filter of the signature list screen: everything the user has on that screen at
	 * once — his own signatures of the owner scope plus the shared ones he is concerned by.
	 *
	 * Differs from buildVisibilityFilter() by the second half: a user who may not manage shared
	 * signatures still sees the ones assigned to his mailboxes, read-only. The REST list keeps the
	 * stricter rule, where being able to see a shared signature and being able to open it by its
	 * identifier is the same right.
	 *
	 * @param bool $includeShared false leaves the screen with the owner scope alone — the interface
	 *                            gate over everything the shared signatures bring in.
	 * @return array<mixed>
	 */
	public function buildListVisibilityFilter(int $userId, bool $includeShared = true): array
	{
		$ownScope = self::buildOwnerFilter($userId, SharedSignatureTable::SCOPE_OWNER);

		if (!$includeShared)
		{
			return $ownScope;
		}

		if (self::canManageSharedScope())
		{
			return ['LOGIC' => 'OR', $ownScope, ['=SCOPE' => SharedSignatureTable::SCOPE_SHARED]];
		}

		$assignedIds = $this->getSharedSignatureIdsAssignedToUser($userId);
		if (empty($assignedIds))
		{
			return $ownScope;
		}

		return [
			'LOGIC' => 'OR',
			$ownScope,
			['=SCOPE' => SharedSignatureTable::SCOPE_SHARED, '=ID' => $assignedIds],
		];
	}

	/**
	 * The same selection as getList(), read as identifiers alone. Serves the queries a screen sends
	 * to the assignments before it selects its rows: passed the visibility filter of that screen,
	 * this names everything such a query may answer by.
	 *
	 * @param array<mixed> $filter
	 * @return int[]
	 */
	public function getSignatureIds(array $filter): array
	{
		$ids = [];
		$result = SharedSignatureTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
		]);
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * IDs of the shared signatures assigned to any mailbox the given user works with — his own and
	 * the ones shared with him, which is the set he may send from.
	 *
	 * @return int[]
	 */
	public function getSharedSignatureIdsAssignedToUser(int $userId, ?SignatureResolver $resolver = null): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$mailboxIds = array_keys(MailboxTable::getUserMailboxes($userId, true));
		if (empty($mailboxIds))
		{
			return [];
		}

		$resolver ??= new SignatureResolver();

		return $resolver->getSharedSignatureIdsForMailboxes(array_map('intval', $mailboxIds));
	}

	/**
	 * Returns a single shared signature with its assignments, or null if not found.
	 */
	public function getById(int $id): ?array
	{
		$signature = SharedSignatureTable::getById($id)->fetchObject();
		if ($signature === null)
		{
			return null;
		}

		return [
			'signature' => $signature,
			'assignments' => $this->getAssignmentsForSignature($id),
		];
	}

	/**
	 * Creates a new signature together with its assignments atomically.
	 *
	 * Defaults keep the behaviour of the corporate signature: without an explicit scope the
	 * signature is shared, without an explicit owner it belongs to its author.
	 *
	 * @param array{signature?: string, scope?: string, ownerId?: int} $fields
	 * @param array<array{targetType: string, targetId: int, targetValue?: string, isFlat: bool}> $assignments
	 */
	public function add(array $fields, array $assignments = []): Result
	{
		$result = new Result();

		$scope = (string)($fields['scope'] ?? SharedSignatureTable::SCOPE_SHARED);
		if (!in_array($scope, SharedSignatureTable::getScopes(), true))
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_INVALID_SCOPE'),
				self::ERROR_INVALID_SCOPE,
			));

			return $result;
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		$ownerId = (int)($fields['ownerId'] ?? $currentUserId);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$signatureEntity = SharedSignatureTable::createObject();
			$signatureEntity->set('CREATED_BY', $currentUserId);
			$signatureEntity->set('OWNER_ID', $ownerId);
			$signatureEntity->set('SCOPE', $scope);
			$signatureEntity->set('SIGNATURE', $fields['signature'] ?? '');
			$signatureEntity->set('DATE_CREATE', new DateTime());
			$signatureEntity->set('DATE_MODIFY', new DateTime());

			$addResult = $signatureEntity->save();
			if (!$addResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$result->addErrors($addResult->getErrors());

				return $result;
			}

			$signatureId = (int)$addResult->getId();

			$assignResult = $this->saveAssignments($signatureId, self::normalizeAssignments($assignments));
			if (!$assignResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$result->addErrors($assignResult->getErrors());

				return $result;
			}

			$connection->commitTransaction();

			$result->setData(['id' => $signatureId]);
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		return $result;
	}

	/**
	 * Updates an existing signature and synchronizes its assignments atomically.
	 *
	 * The scope is updatable (Q-1, default decision) — switching it changes who sees the
	 * signature, and the caller is responsible for the access check that goes with it. When a
	 * shared signature becomes personal, the controller transfers it to the administrator who
	 * made that decision.
	 *
	 * @param array{signature?: string, scope?: string, ownerId?: int} $fields Partial update — only present keys are applied.
	 * @param array<array{targetType: string, targetId: int, targetValue?: string, isFlat: bool}>|null $assignments When null, assignments are not changed.
	 */
	public function update(int $id, array $fields, ?array $assignments = null): Result
	{
		$result = new Result();

		$signature = SharedSignatureTable::getById($id)->fetchObject();
		if ($signature === null)
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_NOT_FOUND'),
				self::ERROR_NOT_FOUND,
			));

			return $result;
		}

		if (array_key_exists('scope', $fields) && !in_array((string)$fields['scope'], SharedSignatureTable::getScopes(), true))
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_INVALID_SCOPE'),
				self::ERROR_INVALID_SCOPE,
			));

			return $result;
		}

		if (
			array_key_exists('scope', $fields)
			&& (string)$fields['scope'] !== (string)$signature->get('SCOPE')
			&& $assignments === null
		)
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_ASSIGNMENTS_REQUIRED_FOR_SCOPE_CHANGE'),
				self::ERROR_ASSIGNMENTS_REQUIRED_FOR_SCOPE_CHANGE,
			));

			return $result;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			if (array_key_exists('signature', $fields))
			{
				$signature->set('SIGNATURE', $fields['signature']);
			}
			if (array_key_exists('scope', $fields))
			{
				$signature->set('SCOPE', (string)$fields['scope']);
			}
			if (array_key_exists('ownerId', $fields))
			{
				$signature->set('OWNER_ID', (int)$fields['ownerId']);
			}
			$signature->set('DATE_MODIFY', new DateTime());

			$saveResult = $signature->save();
			if (!$saveResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$result->addErrors($saveResult->getErrors());

				return $result;
			}

			if ($assignments !== null)
			{
				$syncResult = $this->syncAssignments($id, self::normalizeAssignments($assignments));
				if (!$syncResult->isSuccess())
				{
					$connection->rollbackTransaction();
					$result->addErrors($syncResult->getErrors());

					return $result;
				}
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		return $result;
	}

	/**
	 * Deletes a shared signature and its assignments (cascade at service level).
	 *
	 * The row of the legacy table the signature was copied from goes with it. The migration itself
	 * copies and never deletes, but a legacy row left without its copy would be copied again on the
	 * next access of its owner and the deleted signature would come back.
	 */
	public function delete(int $id): Result
	{
		$result = new Result();

		$migrator = new SignatureMigrator();
		$legacyId = $migrator->findLegacySourceId($id);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$deleteAssignResult = $this->deleteAssignmentsForSignature($id);
			if (!$deleteAssignResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$result->addErrors($deleteAssignResult->getErrors());

				return $result;
			}

			$deleteResult = SharedSignatureTable::delete($id);
			if (!$deleteResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$result->addErrors($deleteResult->getErrors());

				return $result;
			}

			$migrator->dropLegacySource($legacyId);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		return $result;
	}

	/**
	 * Replaces all assignments for a signature atomically.
	 *
	 * @param array<array{targetType: string, targetId: int, isFlat: bool}> $assignments
	 */
	public function assign(int $id, array $assignments): Result
	{
		$result = new Result();

		$signature = SharedSignatureTable::getById($id)->fetchObject();
		if ($signature === null)
		{
			$result->addError(new Error(
				Loc::getMessage('MAIL_SIGNATURE_ERROR_NOT_FOUND'),
				self::ERROR_NOT_FOUND,
			));

			return $result;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$deleteResult = $this->deleteAssignmentsForSignature($id);
			if (!$deleteResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$result->addErrors($deleteResult->getErrors());

				return $result;
			}

			$assignResult = $this->saveAssignments($id, self::normalizeAssignments($assignments));
			if (!$assignResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$result->addErrors($assignResult->getErrors());

				return $result;
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		return $result;
	}

	/**
	 * Tells apart the two things a request without an 'assignments' key may mean.
	 *
	 * An empty list does not survive the form encoding of a request body — the key disappears
	 * together with it — so the absent key alone cannot say whether the caller leaves the
	 * assignments alone or writes an empty set. The caller says which one it means with a flag of
	 * its own: with the flag the answer is the empty set, without it the assignments stay as they
	 * are, which is what every client written before the flag relies on.
	 *
	 * @param array<mixed>|null $assignments
	 * @param mixed $assignmentsProvided Affirmative value — the caller does send the set.
	 * @return array<mixed>|null null leaves the assignments of the signature untouched.
	 */
	public static function readAssignmentsInput(?array $assignments, mixed $assignmentsProvided): ?array
	{
		if ($assignments !== null)
		{
			return $assignments;
		}

		return self::isAffirmative($assignmentsProvided) ? [] : null;
	}

	/**
	 * Brings raw assignment input to the internal shape and drops unknown target types.
	 * A sender target carries its string in targetValue and keeps targetId at 0.
	 *
	 * @param array<array{targetType?: string, targetId?: int, targetValue?: string, isFlat?: bool}> $assignments
	 * @return array<array{targetType: string, targetId: int, targetValue: string, isFlat: bool}>
	 */
	public static function normalizeAssignments(array $assignments): array
	{
		$known = [
			SharedSignatureAssignmentTable::TARGET_ALL,
			SharedSignatureAssignmentTable::TARGET_MAILBOX,
			SharedSignatureAssignmentTable::TARGET_DEPARTMENT,
			SharedSignatureAssignmentTable::TARGET_USER,
			SharedSignatureAssignmentTable::TARGET_SENDER,
		];

		$normalized = [];
		foreach ($assignments as $assignment)
		{
			if (!is_array($assignment))
			{
				continue;
			}

			$type = (string)($assignment['targetType'] ?? $assignment['TARGET_TYPE'] ?? '');
			if (!in_array($type, $known, true))
			{
				continue;
			}

			$isSender = $type === SharedSignatureAssignmentTable::TARGET_SENDER;

			$normalized[] = [
				'targetType' => $type,
				'targetId' => $isSender ? 0 : (int)($assignment['targetId'] ?? $assignment['TARGET_ID'] ?? 0),
				'targetValue' => $isSender
					? (string)($assignment['targetValue'] ?? $assignment['TARGET_VALUE'] ?? '')
					: '',
				'isFlat' => self::isAffirmative($assignment['isFlat'] ?? $assignment['IS_FLAT'] ?? false),
			];
		}

		return $normalized;
	}

	/**
	 * Returns raw assignment rows for a given signature ID.
	 *
	 * @return array<array{ID: int, SIGNATURE_ID: int, TARGET_TYPE: string, TARGET_ID: int, IS_FLAT: bool}>
	 */
	public function getAssignmentsForSignature(int $signatureId): array
	{
		$rows = [];
		$result = SharedSignatureAssignmentTable::getList([
			'filter' => ['=SIGNATURE_ID' => $signatureId],
		]);
		while ($row = $result->fetch())
		{
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Returns raw assignment rows for several signatures at once, grouped by signature ID.
	 *
	 * @param int[] $signatureIds
	 * @return array<int, array<array{ID: int, SIGNATURE_ID: int, TARGET_TYPE: string, TARGET_ID: int, IS_FLAT: bool}>>
	 */
	private function getAssignmentsForSignatures(array $signatureIds): array
	{
		if (empty($signatureIds))
		{
			return [];
		}

		$rowsBySignature = [];
		$result = SharedSignatureAssignmentTable::getList([
			'filter' => ['=SIGNATURE_ID' => $signatureIds],
			'order' => ['ID' => 'ASC'],
		]);
		while ($row = $result->fetch())
		{
			$rowsBySignature[(int)$row['SIGNATURE_ID']][] = $row;
		}

		return $rowsBySignature;
	}

	/**
	 * Saves new assignment rows for a signature (does not delete existing ones).
	 *
	 * @param array<array{targetType: string, targetId: int, targetValue?: string, isFlat: bool}> $assignments
	 */
	private function saveAssignments(int $signatureId, array $assignments): Result
	{
		$result = new Result();

		foreach ($assignments as $assignment)
		{
			$entity = SharedSignatureAssignmentTable::createObject();
			$entity->set('SIGNATURE_ID', $signatureId);
			$entity->set('TARGET_TYPE', $assignment['targetType'] ?? $assignment['TARGET_TYPE'] ?? '');
			$entity->set('TARGET_ID', (int)($assignment['targetId'] ?? $assignment['TARGET_ID'] ?? 0));
			$entity->set('TARGET_VALUE', (string)($assignment['targetValue'] ?? $assignment['TARGET_VALUE'] ?? ''));
			$entity->set(
				'IS_FLAT',
				self::isAffirmative($assignment['isFlat'] ?? $assignment['IS_FLAT'] ?? false) ? 'Y' : 'N',
			);
			$entity->set('DATE_CREATE', new DateTime());

			$saveResult = $entity->save();
			if (!$saveResult->isSuccess())
			{
				$result->addErrors($saveResult->getErrors());

				return $result;
			}
		}

		return $result;
	}

	/**
	 * Deletes all assignment rows for a signature.
	 */
	private function deleteAssignmentsForSignature(int $signatureId): Result
	{
		$result = new Result();

		$rows = SharedSignatureAssignmentTable::getList([
			'select' => ['ID'],
			'filter' => ['=SIGNATURE_ID' => $signatureId],
		]);
		while ($row = $rows->fetch())
		{
			$deleteResult = SharedSignatureAssignmentTable::delete((int)$row['ID']);
			if (!$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());

				return $result;
			}
		}

		return $result;
	}

	/**
	 * Synchronizes assignments by diff: matching rows are left untouched (their
	 * DATE_CREATE is preserved, so editing the signature body does not shift the
	 * "last assigned" order used to pick the default), dropped targets are removed
	 * and new targets are added. Replaces the previous delete-all + recreate on update.
	 *
	 * @param array<array{targetType: string, targetId: int, targetValue?: string, isFlat: bool}> $assignments
	 */
	private function syncAssignments(int $signatureId, array $assignments): Result
	{
		$result = new Result();

		// Index existing assignments by their target key.
		$existing = [];
		foreach ($this->getAssignmentsForSignature($signatureId) as $row)
		{
			$key = self::assignmentKey(
				(string)$row['TARGET_TYPE'],
				(int)$row['TARGET_ID'],
				(string)($row['TARGET_VALUE'] ?? ''),
				$row['IS_FLAT'] === 'Y' || $row['IS_FLAT'] === true,
			);
			$existing[$key] = (int)$row['ID'];
		}

		// Walk desired assignments; collect the ones not present yet. Dedup the input.
		$desiredKeys = [];
		$toAdd = [];
		foreach ($assignments as $assignment)
		{
			$type = (string)($assignment['targetType'] ?? $assignment['TARGET_TYPE'] ?? '');
			$targetId = (int)($assignment['targetId'] ?? $assignment['TARGET_ID'] ?? 0);
			$targetValue = (string)($assignment['targetValue'] ?? $assignment['TARGET_VALUE'] ?? '');
			$isFlat = self::isAffirmative($assignment['isFlat'] ?? $assignment['IS_FLAT'] ?? false);

			$key = self::assignmentKey($type, $targetId, $targetValue, $isFlat);
			if (isset($desiredKeys[$key]))
			{
				continue;
			}
			$desiredKeys[$key] = true;

			if (!array_key_exists($key, $existing))
			{
				$toAdd[] = [
					'targetType' => $type,
					'targetId' => $targetId,
					'targetValue' => $targetValue,
					'isFlat' => $isFlat,
				];
			}
		}

		// Remove dropped assignments.
		foreach ($existing as $key => $rowId)
		{
			if (!array_key_exists($key, $desiredKeys))
			{
				$deleteResult = SharedSignatureAssignmentTable::delete($rowId);
				if (!$deleteResult->isSuccess())
				{
					$result->addErrors($deleteResult->getErrors());

					return $result;
				}
			}
		}

		// Add new assignments (saveAssignments stamps them with the current DATE_CREATE).
		$addResult = $this->saveAssignments($signatureId, $toAdd);
		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		return $result;
	}

	/**
	 * Builds a stable identity key for an assignment target (type + id + string value + flat flag).
	 * The string value participates so that two sender targets differing only by their address
	 * are told apart by the diff.
	 */
	private static function assignmentKey(string $targetType, int $targetId, string $targetValue, bool $isFlat): string
	{
		$value = $targetType === SharedSignatureAssignmentTable::TARGET_SENDER
			? AssignmentResolver::normalizeSenderKey($targetValue)
			: $targetValue;

		return $targetType . '|' . $targetId . '|' . $value . '|' . ($isFlat ? '1' : '0');
	}

	private static function isAffirmative(mixed $value): bool
	{
		return in_array($value, [true, 'true', 'Y', 1, '1'], true);
	}

	// -------------------------------------------------------------------------
	// Access policy — a single place for both scopes (P5.T3)
	// -------------------------------------------------------------------------

	/**
	 * Tells whether the interface of the shared signatures is shown at all: the third card of the
	 * editor, the type column and the shared rows of the list, the way into the screen of the
	 * shared ones. Opt-in module option, so until it is turned on the screens of the personal
	 * signature look exactly as they did.
	 *
	 * Interface only: the unified model is the single way of reading and writing a signature
	 * whatever this returns.
	 */
	public static function isSharedInterfaceEnabled(): bool
	{
		return Option::get('mail', self::INTERFACE_OPTION_NAME, 'N') === 'Y';
	}

	/**
	 * Tells whether the current user may create, change and delete signatures of the
	 * shared scope. Same pair of checks as SharedSignatureAccess: tariff plus the RBAC
	 * right to manage employee mailboxes.
	 *
	 * A shared signature reaches mailboxes the user does not own — up to the whole portal — so it
	 * takes the right to manage mailboxes, not the one to see their grid. Seeing a shared signature
	 * assigned to oneself is a different matter and asks for nothing.
	 */
	public static function canManageSharedScope(): bool
	{
		return LicenseManager::isMailboxManagementEnabled()
			&& MailAccess::hasCurrentUserAccessToMailboxManagement()
		;
	}

	/**
	 * Tells whether the given user may see the signature.
	 * Owner scope — the owner alone; shared scope — anybody allowed to manage shared signatures.
	 *
	 * @param SharedSignature|array $signature Entity object or a raw row.
	 */
	public function canRead($signature, ?int $userId = null): bool
	{
		return $this->isOwnerScope($signature)
			? $this->isOwnedBy($signature, $userId)
			: self::canManageSharedScope()
		;
	}

	/**
	 * Tells whether the given user may change or delete the signature.
	 * A shared signature is governed by the role, not by its author, so the owner of a
	 * shared signature is deliberately not checked.
	 *
	 * @param SharedSignature|array $signature Entity object or a raw row.
	 */
	public function canModify($signature, ?int $userId = null): bool
	{
		return $this->canRead($signature, $userId);
	}

	/**
	 * Tells whether the current user may create a signature of the given scope for the given owner.
	 */
	public function canCreate(string $scope, int $ownerId, ?int $userId = null): bool
	{
		$userId ??= (int)CurrentUser::get()->getId();

		return $scope === SharedSignatureTable::SCOPE_OWNER
			? ($userId > 0 && $ownerId === $userId)
			: self::canManageSharedScope()
		;
	}

	/**
	 * Builds the access-denied error shared by the service and the controllers.
	 */
	public static function accessDeniedError(): Error
	{
		return new Error(
			Loc::getMessage('MAIL_SIGNATURE_ERROR_ACCESS_DENIED'),
			self::ERROR_ACCESS_DENIED,
		);
	}

	/**
	 * @param SharedSignature|array $signature
	 */
	private function isOwnerScope($signature): bool
	{
		return self::readField($signature, 'SCOPE', SharedSignatureTable::SCOPE_SHARED)
			=== SharedSignatureTable::SCOPE_OWNER;
	}

	/**
	 * @param SharedSignature|array $signature
	 */
	private function isOwnedBy($signature, ?int $userId): bool
	{
		$userId ??= (int)CurrentUser::get()->getId();

		return $userId > 0 && (int)self::readField($signature, 'OWNER_ID', 0) === $userId;
	}

	/**
	 * Reads a field from either an ORM object or a raw row, so callers may pass whatever
	 * they already have at hand.
	 *
	 * @param SharedSignature|array $signature
	 */
	private static function readField($signature, string $field, $default)
	{
		if (is_array($signature))
		{
			return $signature[$field] ?? $default;
		}

		if ($signature instanceof SharedSignature)
		{
			return $signature->get($field) ?? $default;
		}

		return $default;
	}
}
