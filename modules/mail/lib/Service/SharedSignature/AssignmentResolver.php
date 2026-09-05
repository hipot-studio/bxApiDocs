<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\SharedSignature;

use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Loader;

/**
 * Resolves a set of SharedSignatureAssignment rows into a flat list of active mailbox IDs.
 * Resolution runs at read time; there is no own cache of the organisation structure.
 *
 * Resolution runs in two passes: the first one collects the target IDs of every assignment
 * and preloads them in batches (one query per target type), the second one walks the
 * assignments in their original order and reads the preloaded data. Everything that has
 * been loaded stays in per-instance caches, so a resolver reused for several signatures
 * queries each mailbox, user and department at most once per request.
 */
class AssignmentResolver
{
	/** @var int[]|null Per-instance cache of all active mailbox IDs (for TARGET_ALL). */
	private ?array $allMailboxIdsCache = null;

	/** @var array<int, bool> mailbox ID → whether it exists and is active. */
	private array $mailboxActiveCache = [];

	/** @var array<int, int[]> user ID → active mailbox IDs owned by the user. */
	private array $userMailboxIdsCache = [];

	/** @var array<string, int[]> "<departmentId>|<isFlat>" → member user IDs. */
	private array $departmentUserIdsCache = [];

	/**
	 * Resolves the given assignments into a unique list of active mailbox IDs.
	 *
	 * TARGET_SENDER contributes nothing here: a sender string is compared with the sender of
	 * the message at read time instead of being expanded into mailboxes, see matchesSender().
	 *
	 * @param array<array{TARGET_TYPE: string, TARGET_ID: int, TARGET_VALUE?: string|null, IS_FLAT?: bool|string}> $assignments
	 * @return int[]
	 */
	public function resolveToMailboxIds(array $assignments): array
	{
		$targets = $this->normalizeTargets($assignments);
		$this->preload($targets);

		$mailboxIds = [];

		foreach ($targets as $target)
		{
			$resolved = match ($target['type'])
			{
				SharedSignatureAssignmentTable::TARGET_ALL => $this->resolveAll(),
				SharedSignatureAssignmentTable::TARGET_MAILBOX => $this->resolveMailbox($target['id']),
				SharedSignatureAssignmentTable::TARGET_DEPARTMENT => $this->resolveDepartment($target['id'], $target['isFlat']),
				SharedSignatureAssignmentTable::TARGET_USER => $this->resolveUser($target['id']),
				default => [],
			};

			foreach ($resolved as $id)
			{
				$mailboxIds[] = (int)$id;
			}
		}

		return array_values(array_unique($mailboxIds));
	}

	/**
	 * Returns the normalized sender keys carried by TARGET_SENDER assignments.
	 * An empty string means "any sender of the owner" and is kept as a value on purpose.
	 *
	 * @param array<array{TARGET_TYPE: string, TARGET_VALUE?: string|null}> $assignments
	 * @return string[]
	 */
	public function collectSenderKeys(array $assignments): array
	{
		$keys = [];

		foreach ($this->normalizeTargets($assignments) as $target)
		{
			if ($target['type'] === SharedSignatureAssignmentTable::TARGET_SENDER)
			{
				$keys[] = self::normalizeSenderKey($target['value']);
			}
		}

		return array_values(array_unique($keys));
	}

	/**
	 * Tells whether any TARGET_SENDER assignment matches the given sender string.
	 *
	 * @param array<array{TARGET_TYPE: string, TARGET_VALUE?: string|null}> $assignments
	 */
	public function matchesSender(array $assignments, string $sender): bool
	{
		return in_array(self::normalizeSenderKey($sender), $this->collectSenderKeys($assignments), true);
	}

	/**
	 * Single rule for comparing sender strings: case-insensitive, trailing spaces stripped.
	 * Server and client must agree on it, so every comparison goes through this method.
	 */
	public static function normalizeSenderKey(string $sender): string
	{
		return mb_strtolower(rtrim($sender, ' '));
	}

	/**
	 * Reduces raw assignment rows to a normalized target list preserving their original order.
	 *
	 * @param array<array{TARGET_TYPE: string, TARGET_ID: int, TARGET_VALUE?: string|null, IS_FLAT?: bool|string}> $assignments
	 * @return array<array{type: string, id: int, value: string, isFlat: bool}>
	 */
	private function normalizeTargets(array $assignments): array
	{
		$targets = [];

		foreach ($assignments as $assignment)
		{
			// ORM BooleanField yields bool, raw rows may carry 'Y'/'N'
			$isFlatValue = $assignment['IS_FLAT'] ?? false;

			$targets[] = [
				'type' => (string)($assignment['TARGET_TYPE'] ?? ''),
				'id' => (int)($assignment['TARGET_ID'] ?? 0),
				'value' => (string)($assignment['TARGET_VALUE'] ?? ''),
				'isFlat' => ($isFlatValue === true || $isFlatValue === 'Y'),
			];
		}

		return $targets;
	}

	/**
	 * Loads everything the targets need in batches: one query for explicit mailboxes and one
	 * for the owners of user and department targets. Department membership still needs a call
	 * per department — the HR API returns a flat list of member IDs with no node attribution,
	 * so a grouped call could not tell which member came from which department.
	 *
	 * @param array<array{type: string, id: int, isFlat: bool}> $targets
	 */
	private function preload(array $targets): void
	{
		$mailboxIds = [];
		$userIds = [];
		$departments = [];

		foreach ($targets as $target)
		{
			if ($target['id'] <= 0)
			{
				continue;
			}

			if ($target['type'] === SharedSignatureAssignmentTable::TARGET_MAILBOX)
			{
				$mailboxIds[$target['id']] = true;
			}
			elseif ($target['type'] === SharedSignatureAssignmentTable::TARGET_USER)
			{
				$userIds[$target['id']] = true;
			}
			elseif ($target['type'] === SharedSignatureAssignmentTable::TARGET_DEPARTMENT)
			{
				$departments[$this->departmentCacheKey($target['id'], $target['isFlat'])] = $target;
			}
		}

		$this->preloadMailboxes(array_keys($mailboxIds));

		foreach ($departments as $key => $department)
		{
			if (!array_key_exists($key, $this->departmentUserIdsCache))
			{
				$this->departmentUserIdsCache[$key] = $this->loadDepartmentMemberIds($department['id'], $department['isFlat']);
			}

			foreach ($this->departmentUserIdsCache[$key] as $memberId)
			{
				$userIds[(int)$memberId] = true;
			}
		}

		$this->preloadUserMailboxes(array_keys($userIds));
	}

	/**
	 * @param int[] $mailboxIds
	 */
	private function preloadMailboxes(array $mailboxIds): void
	{
		$missing = array_values(array_diff($mailboxIds, array_keys($this->mailboxActiveCache)));
		if (empty($missing))
		{
			return;
		}

		$active = $this->loadActiveMailboxIds($missing);

		foreach ($missing as $mailboxId)
		{
			$this->mailboxActiveCache[$mailboxId] = in_array($mailboxId, $active, true);
		}
	}

	/**
	 * @param int[] $userIds
	 */
	private function preloadUserMailboxes(array $userIds): void
	{
		$missing = array_values(array_diff($userIds, array_keys($this->userMailboxIdsCache)));
		if (empty($missing))
		{
			return;
		}

		$loaded = $this->loadMailboxIdsByUserIds($missing);

		foreach ($missing as $userId)
		{
			$this->userMailboxIdsCache[$userId] = $loaded[$userId] ?? [];
		}
	}

	/**
	 * Returns all active mailbox IDs in the system.
	 * Result is memoized per instance so the full scan runs at most once per request.
	 *
	 * @return int[]
	 */
	protected function resolveAll(): array
	{
		if ($this->allMailboxIdsCache === null)
		{
			$this->allMailboxIdsCache = $this->loadAllActiveMailboxIds();
		}

		return $this->allMailboxIdsCache;
	}

	/**
	 * Returns [mailboxId] if the given mailbox is active, or [] otherwise.
	 *
	 * @return int[]
	 */
	protected function resolveMailbox(int $mailboxId): array
	{
		if ($mailboxId <= 0)
		{
			return [];
		}

		$this->preloadMailboxes([$mailboxId]);

		return $this->mailboxActiveCache[$mailboxId] ? [$mailboxId] : [];
	}

	/**
	 * Returns active mailbox IDs for users who belong to the given department.
	 * $isFlat=true limits members to the department itself, otherwise sub-departments are included.
	 *
	 * @return int[]
	 */
	protected function resolveDepartment(int $departmentId, bool $isFlat): array
	{
		if ($departmentId <= 0)
		{
			return [];
		}

		$key = $this->departmentCacheKey($departmentId, $isFlat);
		if (!array_key_exists($key, $this->departmentUserIdsCache))
		{
			$this->departmentUserIdsCache[$key] = $this->loadDepartmentMemberIds($departmentId, $isFlat);
		}

		return $this->getActiveMailboxIdsByUserIds($this->departmentUserIdsCache[$key]);
	}

	/**
	 * Returns active mailbox IDs owned by the given user.
	 *
	 * @return int[]
	 */
	protected function resolveUser(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		return $this->getActiveMailboxIdsByUserIds([$userId]);
	}

	/**
	 * Returns active mailbox IDs for the given user IDs.
	 *
	 * @param int[] $userIds
	 * @return int[]
	 */
	protected function getActiveMailboxIdsByUserIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$userIds = array_map('intval', $userIds);
		$this->preloadUserMailboxes($userIds);

		$mailboxIds = [];
		foreach ($userIds as $userId)
		{
			foreach ($this->userMailboxIdsCache[$userId] ?? [] as $mailboxId)
			{
				$mailboxIds[] = (int)$mailboxId;
			}
		}

		return array_values(array_unique($mailboxIds));
	}

	/**
	 * Loads all active mailbox IDs in the system.
	 *
	 * @return int[]
	 */
	protected function loadAllActiveMailboxIds(): array
	{
		$ids = [];
		$result = MailboxTable::getList([
			'select' => ['ID'],
			'filter' => ['=ACTIVE' => 'Y'],
		]);
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * Loads the subset of the given mailbox IDs that exists and is active.
	 *
	 * @param int[] $mailboxIds
	 * @return int[]
	 */
	protected function loadActiveMailboxIds(array $mailboxIds): array
	{
		if (empty($mailboxIds))
		{
			return [];
		}

		$ids = [];
		$result = MailboxTable::getList([
			'select' => ['ID'],
			'filter' => ['=ID' => $mailboxIds, '=ACTIVE' => 'Y'],
		]);
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * Loads active mailbox IDs of the given users, grouped by owner.
	 *
	 * @param int[] $userIds
	 * @return array<int, int[]> user ID → mailbox IDs
	 */
	protected function loadMailboxIdsByUserIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$mailboxIdsByUser = [];
		$result = MailboxTable::getList([
			'select' => ['ID', 'USER_ID'],
			'filter' => ['=USER_ID' => $userIds, '=ACTIVE' => 'Y'],
		]);
		while ($row = $result->fetch())
		{
			$mailboxIdsByUser[(int)$row['USER_ID']][] = (int)$row['ID'];
		}

		return $mailboxIdsByUser;
	}

	/**
	 * Loads member user IDs of a department, optionally including sub-departments.
	 *
	 * @return int[]
	 */
	protected function loadDepartmentMemberIds(int $departmentId, bool $isFlat): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return $isFlat
			? NodeMemberService::getMemberIdsByDepartmentIds([$departmentId])
			: NodeMemberService::getMembersWithSubDepartments([$departmentId]);
	}

	private function departmentCacheKey(int $departmentId, bool $isFlat): string
	{
		return $departmentId . '|' . ($isFlat ? '1' : '0');
	}
}
