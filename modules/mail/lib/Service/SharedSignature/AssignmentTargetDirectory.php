<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\SharedSignature;

use Bitrix\Mail\Helper\Entity\User\Tool as UserTool;
use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;

/**
 * Names of assignment targets, and the reverse lookup by such a name.
 *
 * The signature list shows in one column whom a signature is assigned to and searches by the same
 * text. Both directions need the name of the target, which the assignment row does not carry: it
 * stores a type plus an identifier, or a raw sender string for the transitional target.
 *
 * Names are read in batches — one query per target type for the whole page — and memoized per
 * instance, so a directory reused between the column and the filter queries each target once.
 */
class AssignmentTargetDirectory
{
	/**
	 * Upper bound of a reverse lookup. Searching narrows a page of the grid down; a query matching
	 * half the portal is a mistake of the person typing it, not a data set worth resolving.
	 */
	private const SEARCH_LIMIT = 500;

	/** Identifiers of a scope per statement, so a wide scope does not turn into one oversized query. */
	private const SCOPE_CHUNK_SIZE = 1000;

	/** @var array<int, string> mailbox ID → title */
	private array $mailboxTitles = [];

	/** @var array<int, string> user ID → formatted name */
	private array $userTitles = [];

	/** @var array<int, string> department (HR node) ID → name */
	private array $departmentTitles = [];

	private UserTool $userTool;

	public function __construct(?UserTool $userTool = null)
	{
		$this->userTool = $userTool ?? new UserTool();
	}

	/**
	 * Titles of the targets of every given signature, in the order of the assignments.
	 * A target that no longer exists contributes nothing: its signature looks unassigned, which is
	 * what it effectively is.
	 *
	 * @param array<int, array<array{TARGET_TYPE: string, TARGET_ID: int, TARGET_VALUE?: string|null}>> $assignmentsBySignature
	 * @return array<int, string[]> signature ID → target titles
	 */
	public function getTitlesBySignature(array $assignmentsBySignature): array
	{
		$rows = [];
		foreach ($assignmentsBySignature as $assignments)
		{
			foreach ($assignments as $assignment)
			{
				$rows[] = $assignment;
			}
		}

		$this->preload($rows);

		$titles = [];
		foreach ($assignmentsBySignature as $signatureId => $assignments)
		{
			$list = [];
			foreach ($assignments as $assignment)
			{
				$title = $this->getTitle($assignment);
				if ($title !== '')
				{
					$list[] = $title;
				}
			}

			$titles[(int)$signatureId] = $list;
		}

		return $titles;
	}

	/**
	 * Titles of the given assignments, one per assignment and in their order. A target that no
	 * longer exists keeps its place with an empty title, so the caller may still tell which
	 * assignment it belongs to.
	 *
	 * @param array<array{TARGET_TYPE: string, TARGET_ID: int, TARGET_VALUE?: string|null}> $assignments
	 * @return string[] Keys of the given assignments.
	 */
	public function getTitles(array $assignments): array
	{
		$this->preload($assignments);

		return array_map(fn(array $assignment): string => $this->getTitle($assignment), $assignments);
	}

	/**
	 * Title of a single target.
	 *
	 * @param array{TARGET_TYPE: string, TARGET_ID: int, TARGET_VALUE?: string|null} $assignment
	 */
	public function getTitle(array $assignment): string
	{
		$type = (string)($assignment['TARGET_TYPE'] ?? '');
		$targetId = (int)($assignment['TARGET_ID'] ?? 0);

		return match ($type)
		{
			SharedSignatureAssignmentTable::TARGET_ALL => (string)Loc::getMessage('MAIL_SIGNATURE_TARGET_ALL'),
			SharedSignatureAssignmentTable::TARGET_SENDER => trim((string)($assignment['TARGET_VALUE'] ?? '')),
			SharedSignatureAssignmentTable::TARGET_MAILBOX => $this->mailboxTitles[$targetId] ?? '',
			SharedSignatureAssignmentTable::TARGET_USER => $this->userTitles[$targetId] ?? '',
			SharedSignatureAssignmentTable::TARGET_DEPARTMENT => $this->departmentTitles[$targetId] ?? '',
			default => '',
		};
	}

	/**
	 * Formatted names of the given users. Serves the author column of the list next to the targets:
	 * the names come from the same place and are cached together, so an employee who is both an
	 * author and a target is read once.
	 *
	 * @param int[] $userIds
	 * @return array<int, string>
	 */
	public function getUserTitles(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));

		$this->loadUserTitles($this->missing($userIds, $this->userTitles));

		$titles = [];
		foreach ($userIds as $userId)
		{
			$titles[$userId] = $this->userTitles[$userId] ?? '';
		}

		return $titles;
	}

	/**
	 * IDs of the signatures whose target matches the given text: a sender string, a mailbox, an
	 * employee, a department, or the whole portal when the text matches the "everybody" title.
	 *
	 * @return int[]
	 */
	public function findSignatureIdsByTarget(string $query): array
	{
		$query = trim($query);
		if ($query === '')
		{
			return [];
		}

		$ids = $this->findSignatureIdsBySender($query);

		$ids = array_merge($ids, $this->findSignatureIdsByTargetIds(
			SharedSignatureAssignmentTable::TARGET_MAILBOX,
			$this->findMailboxIds($query),
		));
		$ids = array_merge($ids, $this->findSignatureIdsByTargetIds(
			SharedSignatureAssignmentTable::TARGET_USER,
			$this->findUserIds($query),
		));
		$ids = array_merge($ids, $this->findSignatureIdsByTargetIds(
			SharedSignatureAssignmentTable::TARGET_DEPARTMENT,
			$this->findDepartmentIds($query),
		));

		if ($this->matchesAllTargetTitle($query))
		{
			$ids = array_merge($ids, $this->findSignatureIdsByTargetIds(
				SharedSignatureAssignmentTable::TARGET_ALL,
				null,
			));
		}

		return array_values(array_unique($ids));
	}

	/**
	 * IDs of the signatures bound to a sender whose string contains the given text. This is the
	 * binding every personal signature has, which is what the live search of the grid looks for.
	 *
	 * The sender is the one target matched by its text rather than by an identifier, so this is also
	 * the only lookup no index of the assignments narrows: the type alone is the largest part of the
	 * table. Hence the scope — the caller names the signatures the answer may come from, and the
	 * search stays a range over IX_MAIL_SSA_SIGNATURE instead of a walk over every binding of the
	 * portal.
	 *
	 * @param int[]|null $signatureIds null — every signature, [] — none of them.
	 * @return int[]
	 */
	public function findSignatureIdsBySender(string $query, ?array $signatureIds = null): array
	{
		$query = trim($query);
		if ($query === '')
		{
			return [];
		}

		return $this->selectSignatureIds(
			[
				'=TARGET_TYPE' => SharedSignatureAssignmentTable::TARGET_SENDER,
				'%TARGET_VALUE' => $query,
			],
			$signatureIds,
		);
	}

	/**
	 * Tells whether the text is a part of the title standing for "every mailbox of the portal".
	 * Typing it has to find the signatures assigned to everybody, the same way the column shows it.
	 */
	private function matchesAllTargetTitle(string $query): bool
	{
		$title = (string)Loc::getMessage('MAIL_SIGNATURE_TARGET_ALL');

		return $title !== '' && mb_stripos($title, $query) !== false;
	}

	/**
	 * IDs of the signatures assigned to the given targets of one type. This is what a chosen entity
	 * of the filter panel asks for: the pair of a type and an identifier is exactly how an
	 * assignment names its target, so no name is involved on this way.
	 *
	 * @param int[]|null $targetIds null — every target of this type, [] — none of them.
	 * @return int[]
	 */
	public function findSignatureIdsByTargetIds(string $targetType, ?array $targetIds): array
	{
		if ($targetIds !== null && empty($targetIds))
		{
			return [];
		}

		$filter = ['=TARGET_TYPE' => $targetType];
		if ($targetIds !== null)
		{
			$filter['=TARGET_ID'] = $targetIds;
		}

		return $this->selectSignatureIds($filter);
	}

	/**
	 * @param array<string, mixed> $filter
	 * @param int[]|null $signatureIds Scope of the lookup; null — every signature, [] — none of them.
	 * @return int[]
	 */
	private function selectSignatureIds(array $filter, ?array $signatureIds = null): array
	{
		if ($signatureIds === null)
		{
			return $this->fetchSignatureIds($filter, self::SEARCH_LIMIT);
		}

		$signatureIds = array_values(array_unique(array_filter(
			array_map('intval', $signatureIds),
			static fn(int $signatureId): bool => $signatureId > 0,
		)));
		if (empty($signatureIds))
		{
			return [];
		}

		// The limit bounds the whole lookup, not a portion of it: chunks share it, and one filled to
		// the brim ends the search the same way a single query cut off at the limit would.
		$ids = [];
		foreach (array_chunk($signatureIds, self::SCOPE_CHUNK_SIZE) as $chunk)
		{
			$limit = self::SEARCH_LIMIT - count($ids);
			if ($limit <= 0)
			{
				break;
			}

			$chunkFilter = $filter;
			$chunkFilter['=SIGNATURE_ID'] = $chunk;

			$ids = array_merge($ids, $this->fetchSignatureIds($chunkFilter, $limit));
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return int[]
	 */
	private function fetchSignatureIds(array $filter, int $limit): array
	{
		$ids = [];
		$result = SharedSignatureAssignmentTable::getList([
			'select' => ['SIGNATURE_ID'],
			'filter' => $filter,
			'limit' => $limit,
		]);
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['SIGNATURE_ID'];
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @return int[]
	 */
	private function findMailboxIds(string $query): array
	{
		$ids = [];
		$result = MailboxTable::getList([
			'select' => ['ID'],
			'filter' => [
				'LOGIC' => 'OR',
				'%EMAIL' => $query,
				'%NAME' => $query,
				'%USERNAME' => $query,
			],
			'limit' => self::SEARCH_LIMIT,
		]);
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * @return int[]
	 */
	private function findUserIds(string $query): array
	{
		$ids = [];
		$result = UserTable::getList([
			'select' => ['ID'],
			'filter' => [
				'LOGIC' => 'OR',
				'%NAME' => $query,
				'%LAST_NAME' => $query,
				'%SECOND_NAME' => $query,
				'%LOGIN' => $query,
				'%EMAIL' => $query,
			],
			'limit' => self::SEARCH_LIMIT,
		]);
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * @return int[]
	 */
	private function findDepartmentIds(string $query): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			$nodes = \Bitrix\HumanResources\Public\Service\Container::getNodeService()->findAllByName(
				name: $query,
				limit: self::SEARCH_LIMIT,
			);
		}
		catch (\Throwable)
		{
			return [];
		}

		$ids = [];
		foreach ($nodes as $node)
		{
			$ids[] = (int)$node->id;
		}

		return $ids;
	}

	/**
	 * Loads the names of every target mentioned in the given assignments, one query per type.
	 *
	 * @param array<array{TARGET_TYPE: string, TARGET_ID: int}> $assignments
	 */
	private function preload(array $assignments): void
	{
		$mailboxIds = [];
		$userIds = [];
		$departmentIds = [];

		foreach ($assignments as $assignment)
		{
			$targetId = (int)($assignment['TARGET_ID'] ?? 0);
			if ($targetId <= 0)
			{
				continue;
			}

			switch ((string)($assignment['TARGET_TYPE'] ?? ''))
			{
				case SharedSignatureAssignmentTable::TARGET_MAILBOX:
					$mailboxIds[$targetId] = true;
					break;

				case SharedSignatureAssignmentTable::TARGET_USER:
					$userIds[$targetId] = true;
					break;

				case SharedSignatureAssignmentTable::TARGET_DEPARTMENT:
					$departmentIds[$targetId] = true;
					break;
			}
		}

		$this->loadMailboxTitles($this->missing(array_keys($mailboxIds), $this->mailboxTitles));
		$this->loadUserTitles($this->missing(array_keys($userIds), $this->userTitles));
		$this->loadDepartmentTitles($this->missing(array_keys($departmentIds), $this->departmentTitles));
	}

	/**
	 * @param int[] $ids
	 * @param array<int, string> $loaded
	 * @return int[]
	 */
	private function missing(array $ids, array $loaded): array
	{
		return array_values(array_diff($ids, array_keys($loaded)));
	}

	/**
	 * @param int[] $mailboxIds
	 */
	private function loadMailboxTitles(array $mailboxIds): void
	{
		if (empty($mailboxIds))
		{
			return;
		}

		// Deactivated mailboxes are named as well: a signature assigned to one is still assigned.
		$result = MailboxTable::getList([
			'select' => ['ID', 'EMAIL', 'NAME', 'USERNAME'],
			'filter' => ['=ID' => $mailboxIds],
		]);
		while ($row = $result->fetch())
		{
			$title = (string)($row['EMAIL'] ?? '');
			if ($title === '')
			{
				$title = (string)($row['NAME'] ?? $row['USERNAME'] ?? '');
			}

			$this->mailboxTitles[(int)$row['ID']] = $title;
		}
	}

	/**
	 * @param int[] $userIds
	 */
	private function loadUserTitles(array $userIds): void
	{
		if (empty($userIds))
		{
			return;
		}

		$result = UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL'],
			'filter' => ['=ID' => $userIds],
		]);
		while ($row = $result->fetch())
		{
			$this->userTitles[(int)$row['ID']] = $this->userTool->formatName($row);
		}
	}

	/**
	 * @param int[] $departmentIds
	 */
	private function loadDepartmentTitles(array $departmentIds): void
	{
		if (empty($departmentIds) || !Loader::includeModule('humanresources'))
		{
			return;
		}

		try
		{
			$nodes = \Bitrix\HumanResources\Public\Service\Container::getNodeService()->findAllByIds(
				nodeIds: $departmentIds,
			);
		}
		catch (\Throwable)
		{
			return;
		}

		foreach ($nodes as $node)
		{
			$this->departmentTitles[(int)$node->id] = (string)$node->name;
		}
	}
}
