<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\SharedSignature;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Model\NodePathTable;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Internals\UserSignatureTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Loader;

/**
 * Resolves the effective signature set for a given mailbox.
 *
 * ALG-01 (NORMATIVE):
 *   choice   = storage.get(userId, senderKey)      # id of the signature and time of the choice
 *   assigned = signatures.assignedTo(mailboxId)    # scope=shared, by assignment date desc
 *
 *   if choice and signatures.isAvailable(choice.id, userId, assigned):
 *       if not assigned or choice.time > assigned[0].assignedAt:
 *           return choice.id
 *
 *   if assigned:
 *       return assigned[0].id
 *
 *   return personalDefault(userId, senderEmail, senderName)
 *
 * A reference to a signature that is gone counts as no choice at all. An assignment made later
 * than the choice wins over it, so every new decision of the administrator reaches the employee
 * exactly once.
 *
 * Read-time resolver; no own organisation-structure cache.
 */
class SignatureResolver
{
	private AssignmentResolver $assignmentResolver;

	private SignatureChoiceStorage $choiceStorage;

	public function __construct(
		?AssignmentResolver $assignmentResolver = null,
		?SignatureChoiceStorage $choiceStorage = null,
	)
	{
		$this->assignmentResolver = $assignmentResolver ?? new AssignmentResolver();
		$this->choiceStorage = $choiceStorage ?? new SignatureChoiceStorage();
	}

	/**
	 * Returns the default signature text for the given mailbox.
	 *
	 * Priority (ALG-01):
	 *   1. The signature the employee picked for this sender, unless a shared signature has been
	 *      assigned to the mailbox after that choice.
	 *   2. Last-assigned shared signature for the mailbox (assignment DATE_CREATE desc).
	 *   3. Personal default for ownerUserId / senderEmail.
	 *
	 * @param int    $mailboxId
	 * @param int    $ownerUserId
	 * @param string $senderEmail  Sender address used to look up the personal signature.
	 * @param string $senderName   Display name used together with $senderEmail.
	 * @return string
	 */
	public function resolveDefault(int $mailboxId, int $ownerUserId, string $senderEmail, string $senderName = ''): string
	{
		$sharedSignatures = $this->getAssignedSharedSignatures($mailboxId);

		$choice = $this->choiceStorage->get(
			$ownerUserId,
			SignatureChoiceStorage::buildSenderKey($senderEmail, $senderName),
		);

		if ($choice !== null)
		{
			$chosenSignature = $this->findSignatureText(
				$ownerUserId,
				$choice['id'],
				array_column($sharedSignatures, 'ID'),
			);
			$latestAssignedAt = (int)($sharedSignatures[0]['ASSIGNED_AT'] ?? 0);

			if ($chosenSignature !== null && (empty($sharedSignatures) || $choice['time'] > $latestAssignedAt))
			{
				return $chosenSignature;
			}
		}

		if (!empty($sharedSignatures))
		{
			// Already ordered by assignment DATE_CREATE desc — return first
			return (string)($sharedSignatures[0]['SIGNATURE'] ?? '');
		}

		return $this->resolvePersonalDefault($ownerUserId, $senderEmail, $senderName);
	}

	/**
	 * Returns the text of an available signature a remembered choice points at, or null.
	 *
	 * The unified model answers first; personal rows are the fallback path that lives inside
	 * mail until the migration is over. A signature of the owner scope is only visible to its
	 * owner, so somebody else's choice identifier resolves to nothing. A shared signature is
	 * available only while it remains assigned to the current mailbox.
	 *
	 * A row of the unified model the user cannot see is not the end of the search: while the
	 * migration is running the very same number may name a personal row of his own that has not
	 * been copied yet, and the two sequences are independent of each other.
	 *
	 * @param int[] $assignedSharedSignatureIds
	 */
	protected function findSignatureText(
		int $userId,
		int $signatureId,
		array $assignedSharedSignatureIds = [],
	): ?string
	{
		if ($signatureId <= 0)
		{
			return null;
		}

		$row = SharedSignatureTable::getList([
			'select' => ['ID', 'SIGNATURE', 'SCOPE', 'OWNER_ID'],
			'filter' => ['=ID' => $signatureId],
			'limit' => 1,
		])->fetch();

		if ($row)
		{
			$isShared = (string)$row['SCOPE'] === SharedSignatureTable::SCOPE_SHARED;
			$isVisible = $isShared
				? in_array($signatureId, $assignedSharedSignatureIds, true)
				: (int)$row['OWNER_ID'] === $userId
			;

			if ($isVisible)
			{
				return (string)($row['SIGNATURE'] ?? '');
			}
		}

		foreach ($this->getPersonalSignatureRows($userId) as $personalRow)
		{
			if ((int)($personalRow['ID'] ?? 0) === $signatureId)
			{
				return (string)($personalRow['SIGNATURE'] ?? '');
			}
		}

		return null;
	}

	/**
	 * Returns the full list of available signatures for a mailbox.
	 *
	 * Personal signatures are returned first (as-is, no isShared flag),
	 * followed by assigned shared signatures (isShared = true, signatureId = int,
	 * assignedAt = the moment of the latest assignment as a unix timestamp, null when unknown).
	 *
	 * The consumer resolving the default on its own needs assignedAt: ALG-01 compares it with the
	 * moment of the remembered choice.
	 *
	 * @param int $mailboxId
	 * @param int $ownerUserId
	 * @return array<array{id?: int, signature: string, sender?: string, isShared?: bool, signatureId?: int, assignedAt?: int|null}>
	 */
	public function resolveList(int $mailboxId, int $ownerUserId): array
	{
		$result = [];

		// Personal signatures
		$personalRows = $this->getPersonalSignatureRows($ownerUserId);
		foreach ($personalRows as $row)
		{
			$result[] = [
				// The identifier is what a remembered choice refers to, so a personal
				// signature carries it too.
				'id' => (int)($row['ID'] ?? 0),
				'signature' => (string)($row['SIGNATURE'] ?? ''),
				'sender' => (string)($row['SENDER'] ?? ''),
				'isShared' => false,
			];
		}

		// Assigned shared signatures
		$sharedSignatures = $this->getAssignedSharedSignatures($mailboxId);
		foreach ($sharedSignatures as $row)
		{
			$assignedAt = $row['ASSIGNED_AT'] ?? null;

			$result[] = [
				'signatureId' => (int)$row['ID'],
				'signature' => (string)($row['SIGNATURE'] ?? ''),
				'isShared' => true,
				'assignedAt' => $assignedAt === null ? null : (int)$assignedAt,
			];
		}

		return $result;
	}

	/**
	 * Returns shared signatures grouped by the requested active mailbox IDs.
	 *
	 * @param int[] $mailboxIds
	 * @return array<int, array<array{signatureId: int, signature: string, isShared: true, assignedAt: int|null}>>
	 */
	public function resolveSharedForMailboxes(array $mailboxIds): array
	{
		$result = [];
		foreach ($this->getAssignedSharedSignaturesForMailboxes($mailboxIds) as $mailboxId => $signatures)
		{
			$result[$mailboxId] = [];
			foreach ($signatures as $signature)
			{
				$assignedAt = $signature['ASSIGNED_AT'] ?? null;
				$result[$mailboxId][] = [
					'signatureId' => (int)$signature['ID'],
					'signature' => (string)($signature['SIGNATURE'] ?? ''),
					'isShared' => true,
					'assignedAt' => $assignedAt === null ? null : (int)$assignedAt,
				];
			}
		}

		return $result;
	}

	/**
	 * IDs of the shared signatures assigned to at least one of the given mailboxes.
	 *
	 * Serves the signature list, which asks the question for a whole set of mailboxes at once and
	 * needs no ordering: the answer is a filter over the unified model, and the grid orders the
	 * rows itself. Sender targets take no part here, the same as in resolveToMailboxIds().
	 *
	 * @param int[] $mailboxIds
	 * @return int[]
	 */
	public function getSharedSignatureIdsForMailboxes(array $mailboxIds): array
	{
		$mailboxIds = array_values(array_filter(array_map('intval', $mailboxIds), static fn(int $id): bool => $id > 0));
		if (empty($mailboxIds))
		{
			return [];
		}

		$signatureIds = [];
		foreach ($this->getAssignedSharedSignaturesForMailboxes($mailboxIds) as $signatures)
		{
			foreach ($signatures as $signature)
			{
				$signatureIds[(int)$signature['ID']] = true;
			}
		}

		return array_keys($signatureIds);
	}

	/**
	 * Returns shared signatures assigned to the given mailbox,
	 * ordered by assignment DATE_CREATE desc (latest assignment first).
	 *
	 * Uses AssignmentResolver to evaluate all assignment types (all/mailbox/department)
	 * without duplicating HR/department logic.
	 *
	 * ASSIGNED_AT carries the moment of the latest assignment as a timestamp — ALG-01 compares
	 * it with the moment of the remembered choice.
	 *
	 * @param int $mailboxId
	 * @return array<array{ID: int, SIGNATURE: string, ASSIGNED_AT: int|null}>
	 */
	protected function getAssignedSharedSignatures(int $mailboxId): array
	{
		if ($mailboxId <= 0)
		{
			return [];
		}

		return $this->getAssignedSharedSignaturesForMailboxes([$mailboxId])[$mailboxId] ?? [];
	}

	/**
	 * @param int[] $mailboxIds
	 * @return array<int, array<array{ID: int, SIGNATURE: string, ASSIGNED_AT: int|null}>>
	 */
	protected function getAssignedSharedSignaturesForMailboxes(array $mailboxIds): array
	{
		$mailboxIds = array_values(array_unique(array_filter(
			array_map('intval', $mailboxIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($mailboxIds))
		{
			return [];
		}

		$mailboxOwners = $this->loadMailboxOwners($mailboxIds);
		$mailboxIds = array_keys($mailboxOwners);
		if (empty($mailboxIds))
		{
			return [];
		}

		$ownerIds = array_values(array_unique(array_values($mailboxOwners)));
		$departmentsByOwner = $this->loadDepartmentIdsByUserIds($ownerIds);
		$departmentIds = [];
		foreach ($departmentsByOwner as $departments)
		{
			foreach (array_merge($departments['flat'] ?? [], $departments['recursive'] ?? []) as $departmentId)
			{
				$departmentIds[(int)$departmentId] = true;
			}
		}

		$assignments = $this->loadCandidateAssignments(
			$mailboxIds,
			$ownerIds,
			array_keys($departmentIds),
		);
		if (empty($assignments))
		{
			return array_fill_keys($mailboxIds, []);
		}

		$signatureIds = array_values(array_unique(array_map(
			static fn(array $assignment): int => (int)$assignment['SIGNATURE_ID'],
			$assignments,
		)));
		$signatures = $this->loadSharedSignatures($signatureIds);
		$signatureIdsByMailbox = array_fill_keys($mailboxIds, []);
		$orderMetadataByMailbox = array_fill_keys($mailboxIds, []);

		foreach ($assignments as $assignment)
		{
			$signatureId = (int)$assignment['SIGNATURE_ID'];
			if (!isset($signatures[$signatureId]))
			{
				continue;
			}

			foreach ($mailboxOwners as $mailboxId => $ownerId)
			{
				if ($this->assignmentMatchesMailbox($assignment, $mailboxId, $ownerId, $departmentsByOwner))
				{
					$signatureIdsByMailbox[$mailboxId][$signatureId] = true;

					$currentMetadata = $orderMetadataByMailbox[$mailboxId][$signatureId] ?? null;
					$assignmentDate = $assignment['DATE_CREATE'] ?? null;
					$assignmentId = (int)$assignment['ID'];
					$currentTimestamp = ($currentMetadata['date'] ?? null)?->getTimestamp();
					$assignmentTimestamp = $assignmentDate?->getTimestamp();

					if (
						$currentMetadata === null
						|| $assignmentTimestamp > $currentTimestamp
						|| ($assignmentTimestamp === $currentTimestamp && $assignmentId > $currentMetadata['id'])
					)
					{
						$orderMetadataByMailbox[$mailboxId][$signatureId] = [
							'date' => $assignmentDate,
							'id' => $assignmentId,
						];
					}
				}
			}
		}

		$result = [];
		foreach ($signatureIdsByMailbox as $mailboxId => $assignedSignatureIds)
		{
			$result[$mailboxId] = [];
			foreach (array_keys($assignedSignatureIds) as $signatureId)
			{
				$metadata = $orderMetadataByMailbox[$mailboxId][$signatureId] ?? [];
				$result[$mailboxId][] = [
					'ID' => $signatureId,
					'SIGNATURE' => (string)$signatures[$signatureId]['SIGNATURE'],
					'ASSIGNED_AT' => isset($metadata['date']) ? $metadata['date']->getTimestamp() : null,
					'_latestDate' => $metadata['date'] ?? null,
					'_maxAssignmentId' => (int)($metadata['id'] ?? 0),
				];
			}

			usort($result[$mailboxId], self::compareAssignedSignatures(...));
			foreach ($result[$mailboxId] as &$signature)
			{
				unset($signature['_latestDate'], $signature['_maxAssignmentId']);
			}
			unset($signature);
		}

		return $result;
	}

	private static function compareAssignedSignatures(array $a, array $b): int
	{
		$left = $a['_latestDate'];
		$right = $b['_latestDate'];
		$leftTimestamp = $left?->getTimestamp();
		$rightTimestamp = $right?->getTimestamp();

		if ($leftTimestamp !== $rightTimestamp)
		{
			return $rightTimestamp <=> $leftTimestamp;
		}

		return $b['_maxAssignmentId'] <=> $a['_maxAssignmentId'];
	}

	/**
	 * @param int[] $mailboxIds
	 * @return array<int, int>
	 */
	protected function loadMailboxOwners(array $mailboxIds): array
	{
		$result = [];
		$rows = MailboxTable::getList([
			'select' => ['ID', 'USER_ID'],
			'filter' => ['=ID' => $mailboxIds, '=ACTIVE' => 'Y'],
		]);
		while ($row = $rows->fetch())
		{
			$result[(int)$row['ID']] = (int)$row['USER_ID'];
		}

		return $result;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, array{flat: int[], recursive: int[]}>
	 */
	protected function loadDepartmentIdsByUserIds(array $userIds): array
	{
		$result = array_fill_keys($userIds, ['flat' => [], 'recursive' => []]);
		if (empty($userIds) || !Loader::includeModule('humanresources'))
		{
			return $result;
		}

		$members = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					entityIdFilter: EntityIdFilter::fromEntityIds(array_values($userIds)),
					nodeFilter: new NodeFilter(
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
						depthLevel: 0,
					),
				),
			)
			->getAll()
		;

		$ownersByNode = [];
		foreach ($members as $member)
		{
			$result[$member->entityId]['flat'][$member->nodeId] = $member->nodeId;
			$result[$member->entityId]['recursive'][$member->nodeId] = $member->nodeId;
			$ownersByNode[$member->nodeId][$member->entityId] = true;
		}
		if (empty($ownersByNode))
		{
			return $result;
		}

		$paths = NodePathTable::query()
			->setSelect(['PARENT_ID', 'CHILD_ID'])
			->whereIn('CHILD_ID', array_keys($ownersByNode))
			->exec()
		;
		while ($path = $paths->fetch())
		{
			foreach (array_keys($ownersByNode[(int)$path['CHILD_ID']] ?? []) as $ownerId)
			{
				$result[$ownerId]['recursive'][(int)$path['PARENT_ID']] = (int)$path['PARENT_ID'];
			}
		}

		foreach ($result as &$departments)
		{
			$departments['flat'] = array_values($departments['flat']);
			$departments['recursive'] = array_values($departments['recursive']);
		}
		unset($departments);

		return $result;
	}

	/**
	 * @param int[] $mailboxIds
	 * @param int[] $ownerIds
	 * @param int[] $departmentIds
	 */
	protected function loadCandidateAssignments(array $mailboxIds, array $ownerIds, array $departmentIds): array
	{
		$filter = [
			'LOGIC' => 'OR',
			['=TARGET_TYPE' => SharedSignatureAssignmentTable::TARGET_ALL],
			[
				'=TARGET_TYPE' => SharedSignatureAssignmentTable::TARGET_MAILBOX,
				'=TARGET_ID' => $mailboxIds,
			],
			[
				'=TARGET_TYPE' => SharedSignatureAssignmentTable::TARGET_USER,
				'=TARGET_ID' => $ownerIds,
			],
		];
		if (!empty($departmentIds))
		{
			$filter[] = [
				'=TARGET_TYPE' => SharedSignatureAssignmentTable::TARGET_DEPARTMENT,
				'=TARGET_ID' => $departmentIds,
			];
		}

		return SharedSignatureAssignmentTable::getList([
			'select' => ['ID', 'SIGNATURE_ID', 'TARGET_TYPE', 'TARGET_ID', 'IS_FLAT', 'DATE_CREATE'],
			'filter' => $filter,
		])->fetchAll();
	}

	/**
	 * @param int[] $signatureIds
	 * @return array<int, array{ID: int, SIGNATURE: string}>
	 */
	protected function loadSharedSignatures(array $signatureIds): array
	{
		if (empty($signatureIds))
		{
			return [];
		}

		$result = [];
		$rows = SharedSignatureTable::getList([
			'select' => ['ID', 'SIGNATURE'],
			'filter' => [
				'=ID' => $signatureIds,
				'=SCOPE' => SharedSignatureTable::SCOPE_SHARED,
			],
		]);
		while ($row = $rows->fetch())
		{
			$result[(int)$row['ID']] = $row;
		}

		return $result;
	}

	/**
	 * @param array<int, array{flat: int[], recursive: int[]}> $departmentsByOwner
	 */
	private function assignmentMatchesMailbox(
		array $assignment,
		int $mailboxId,
		int $ownerId,
		array $departmentsByOwner,
	): bool
	{
		$targetType = (string)$assignment['TARGET_TYPE'];
		$targetId = (int)$assignment['TARGET_ID'];

		return match ($targetType)
		{
			SharedSignatureAssignmentTable::TARGET_ALL => true,
			SharedSignatureAssignmentTable::TARGET_MAILBOX => $targetId === $mailboxId,
			SharedSignatureAssignmentTable::TARGET_USER => $targetId === $ownerId,
			SharedSignatureAssignmentTable::TARGET_DEPARTMENT => in_array(
				$targetId,
				($assignment['IS_FLAT'] ?? false) === true || ($assignment['IS_FLAT'] ?? null) === 'Y'
					? ($departmentsByOwner[$ownerId]['flat'] ?? [])
					: ($departmentsByOwner[$ownerId]['recursive'] ?? []),
				true,
			),
			default => false,
		};
	}

	/**
	 * Resolves the personal default signature for a user and sender address.
	 *
	 * Priority mirrors SignatureProvider (mailmobile):
	 *   1. "Name <email>" exact match
	 *   2. "email" match
	 *   3. General signature (empty SENDER / '' key)
	 *
	 * @param int    $userId
	 * @param string $email
	 * @param string $name
	 * @return string
	 */
	protected function resolvePersonalDefault(int $userId, string $email, string $name = ''): string
	{
		$signatures = $this->getPersonalSignatureMap($userId);

		if ($name !== '' && $email !== '')
		{
			$nameEmailKey = $this->normalizeSender(trim($name) . ' <' . trim($email) . '>');
			if (isset($signatures[$nameEmailKey]))
			{
				return $signatures[$nameEmailKey];
			}
		}

		if ($email !== '')
		{
			$emailKey = $this->normalizeSender(trim($email));
			if (isset($signatures[$emailKey]))
			{
				return $signatures[$emailKey];
			}
		}

		// General / empty-sender signature
		return $signatures[''] ?? '';
	}

	/**
	 * Returns raw personal signature rows for a user (for list building).
	 *
	 * @param int $userId
	 * @return array<array{ID: int, SENDER: string|null, SIGNATURE: string|null}>
	 */
	protected function getPersonalSignatureRows(int $userId): array
	{
		$rows = [];
		$result = UserSignatureTable::getList([
			'select' => ['ID', 'SENDER', 'SIGNATURE'],
			'filter' => ['=USER_ID' => $userId],
			'order' => ['ID' => 'ASC'],
		]);
		while ($row = $result->fetch())
		{
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Returns normalised sender → signature map for the user (used for default resolution).
	 *
	 * @param int $userId
	 * @return array<string, string>
	 */
	protected function getPersonalSignatureMap(int $userId): array
	{
		$map = [];
		foreach ($this->getPersonalSignatureRows($userId) as $row)
		{
			if ($row['SENDER'] === null || $row['SIGNATURE'] === null)
			{
				continue;
			}
			$key = $this->normalizeSender((string)$row['SENDER']);
			$map[$key] = (string)$row['SIGNATURE'];
		}

		return $map;
	}

	/**
	 * Sender key normalization. Kept in one place for the whole module, see
	 * AssignmentResolver::normalizeSenderKey().
	 */
	private function normalizeSender(string $sender): string
	{
		return AssignmentResolver::normalizeSenderKey($sender);
	}
}
