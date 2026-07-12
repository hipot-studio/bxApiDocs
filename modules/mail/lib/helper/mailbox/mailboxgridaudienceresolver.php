<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\HumanResources\Service\Container;
use Bitrix\Mail\Access\MailAccessController;
use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Permission\PermissionVariablesDictionary;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

class MailboxGridAudienceResolver
{
	private int $userId;

	public function __construct(?int $userId = null)
	{
		$this->userId = $userId ?? (int)CurrentUser::get()->getId();
	}

	public function canViewOwnerMailbox(int $ownerId): bool
	{
		return in_array($ownerId, $this->getVisibleOwnerIds([$ownerId]), true);
	}

	public function canEditOwnerMailbox(int $ownerId): bool
	{
		return in_array($ownerId, $this->getEditableOwnerIds([$ownerId]), true);
	}

	/**
	 * @param int[] $ownerIds
	 * @return int[]
	 */
	public function getVisibleOwnerIds(array $ownerIds): array
	{
		return $this->resolveOwnerIds(
			$this->normalizeIds($ownerIds),
			PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_VIEW,
		);
	}

	/**
	 * @param int[] $ownerIds
	 * @return int[]
	 */
	public function getEditableOwnerIds(array $ownerIds): array
	{
		return $this->resolveOwnerIds(
			$this->normalizeIds($ownerIds),
			PermissionDictionary::MAIL_MAILBOX_LIST_ITEM_EDIT,
		);
	}

	/**
	 * @param int[] $mailboxIds
	 * @return int[]
	 */
	public function getEditableProblemMailboxIds(array $mailboxIds = []): array
	{
		$problemMailboxRows = $this->getProblemMailboxRows($this->normalizeIds($mailboxIds));
		if (empty($problemMailboxRows))
		{
			return [];
		}

		$ownerIds = $this->normalizeIds(array_column($problemMailboxRows, 'USER_ID'));
		$visibleOwnerIdsMap = array_flip($this->getVisibleOwnerIds($ownerIds));
		$editableOwnerIdsMap = array_flip($this->getEditableOwnerIds($ownerIds));

		$editableProblemMailboxIds = [];
		foreach ($problemMailboxRows as $problemMailboxRow)
		{
			$ownerId = (int)($problemMailboxRow['USER_ID'] ?? 0);
			if (isset($visibleOwnerIdsMap[$ownerId], $editableOwnerIdsMap[$ownerId]))
			{
				$editableProblemMailboxIds[] = (int)($problemMailboxRow['ID'] ?? 0);
			}
		}

		return $this->normalizeIds($editableProblemMailboxIds);
	}

	protected function getPermissionValue(string $permissionId): ?int
	{
		return MailboxAccess::getPermissionValue($permissionId, $this->userId);
	}

	/**
	 * @return int[]
	 */
	protected function getNodeIds(): array
	{
		if ($this->userId <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		return $this->normalizeIds(
			Container::getNodeRepository()->findAllByUserId($this->userId)->getIds(),
		);
	}

	/**
	 * @param int[] $ownerIds
	 * @param int[] $nodeIds
	 * @return int[]
	 */
	protected function getDepartmentOwnerIds(array $ownerIds, array $nodeIds, bool $withSubdepartments): array
	{
		return $this->normalizeIds(
			NodeMemberService::filterUsersByDepartmentIds(
				$ownerIds,
				$nodeIds,
				$withSubdepartments,
			),
		);
	}

	/**
	 * @param int[] $mailboxIds
	 * @return array<int, array{ID:int, USER_ID:int}>
	 */
	protected function getProblemMailboxRows(array $mailboxIds = []): array
	{
		$problemOptionQuery = MailEntityOptionsTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE', MailEntityOptionsTable::MAILBOX_TYPE_NAME)
			->where('PROPERTY_NAME', MailEntityOptionsTable::PROBLEM_STATUS_PROPERTY_NAME)
			->where('VALUE', 'Y')
		;

		if (!empty($mailboxIds))
		{
			$problemOptionQuery->whereIn('ENTITY_ID', array_map('strval', $mailboxIds));
		}

		$problemMailboxIds = $this->normalizeIds(
			array_column($problemOptionQuery->fetchAll(), 'ENTITY_ID'),
		);
		if (empty($problemMailboxIds))
		{
			return [];
		}

		return MailboxTable::query()
			->setSelect(['ID', 'USER_ID'])
			->where('ACTIVE', 'Y')
			->whereIn('ID', $problemMailboxIds)
			->fetchAll()
		;
	}

	/**
	 * @param int[] $ownerIds
	 * @return int[]
	 */
	private function resolveOwnerIds(array $ownerIds, string $permissionId): array
	{
		if ($this->userId <= 0 || empty($ownerIds) || !$this->hasMailboxGridAccess())
		{
			return [];
		}

		$permissionValue = $this->getPermissionValue($permissionId);
		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_ALL)
		{
			return $ownerIds;
		}

		$resolvedOwnerIds = in_array($this->userId, $ownerIds, true)
			? [$this->userId]
			: []
		;

		if (
			$permissionValue === PermissionVariablesDictionary::VARIABLE_NONE
			|| $permissionValue === null
		)
		{
			return $resolvedOwnerIds;
		}

		$nodeIds = $this->getNodeIds();
		if (empty($nodeIds))
		{
			return $resolvedOwnerIds;
		}

		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_SELF_DEPARTMENTS)
		{
			return $this->mergeOwnerIds(
				$resolvedOwnerIds,
				$this->getDepartmentOwnerIds($ownerIds, $nodeIds, withSubdepartments: false),
			);
		}

		if ($permissionValue === PermissionVariablesDictionary::VARIABLE_DEPARTMENT_WITH_SUBDEPARTMENTS)
		{
			return $this->mergeOwnerIds(
				$resolvedOwnerIds,
				$this->getDepartmentOwnerIds($ownerIds, $nodeIds, withSubdepartments: true),
			);
		}

		return $resolvedOwnerIds;
	}

	protected function hasMailboxGridAccess(): bool
	{
		return MailAccessController::getInstance($this->userId)->check(
			MailActionDictionary::ACTION_MAILBOX_LIST_VIEW,
		);
	}

	/**
	 * @param int[] $baseOwnerIds
	 * @param int[] $extraOwnerIds
	 * @return int[]
	 */
	private function mergeOwnerIds(array $baseOwnerIds, array $extraOwnerIds): array
	{
		return $this->normalizeIds(array_merge($baseOwnerIds, $extraOwnerIds));
	}

	/**
	 * @param array<int|string> $ids
	 * @return int[]
	 */
	private function normalizeIds(array $ids): array
	{
		$normalizedIds = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalizedIds[$id] = $id;
			}
		}

		return array_values($normalizedIds);
	}
}
