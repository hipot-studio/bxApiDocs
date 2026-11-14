<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Dictionary\OfficeEmployeeErrorCode;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\IdNormalizer;
use Bitrix\StaffTrack\Internal\Integration\Intranet\EmployeeEligibility;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Item\OfficeStaffPlan;
use Bitrix\StaffTrack\Item\OfficeStaffSelection;

/**
 * A parsed selection turned into the staff it stands for, the people of it who would be moved from
 * another office and the fingerprint of those moves (ALG-01).
 *
 * A new office takes the whole selection and holds it to the limit. An edited office takes only those
 * who are not on it yet and not removed, and the limit counts them alone; the selection itself is held
 * to the ceiling of the expansion instead.
 *
 * The codes the parse could not take are not looked at here: refusing them, and in what shape, is the
 * business of the caller, and this service expands only what the selection holds.
 */
class StaffPlanService
{
	public const STAFF_LIMIT = 1000;
	public const EXPANSION_CEILING = 5000;

	private EmployeeSetResolver $employeeSetResolver;
	private EmployeeEligibility $employeeEligibility;
	private OfficeEmployeeRepository $officeEmployeeRepository;

	public function __construct(
		?EmployeeSetResolver $employeeSetResolver = null,
		?EmployeeEligibility $employeeEligibility = null,
		?OfficeEmployeeRepository $officeEmployeeRepository = null,
	)
	{
		$this->employeeSetResolver = $employeeSetResolver ?? new EmployeeSetResolver();
		$this->employeeEligibility = $employeeEligibility ?? new EmployeeEligibility();
		$this->officeEmployeeRepository = $officeEmployeeRepository ?? new OfficeEmployeeRepository();
	}

	/**
	 * The plan of a new office.
	 *
	 * @return Result data of a success: {plan: OfficeStaffPlan}
	 */
	public function build(OfficeStaffSelection $selection): Result
	{
		$expandResult = $this->expand($selection);
		if (!$expandResult->isSuccess())
		{
			return $expandResult;
		}

		return $this->planFor($expandResult->getData()['userIds']);
	}

	/**
	 * The plan of an edited office.
	 *
	 * @param int[] $removedUserIds
	 * @return Result data of a success: {plan: OfficeStaffPlan}
	 */
	public function buildForOffice(OfficeStaffSelection $selection, int $officeId, array $removedUserIds): Result
	{
		$expandResult = $this->expandForOffice($selection);
		if (!$expandResult->isSuccess())
		{
			return $expandResult;
		}

		return $this->planFor($expandResult->getData()['userIds'], $officeId, $removedUserIds);
	}

	/**
	 * @return Result data of a success: {userIds: int[]}
	 */
	public function expand(OfficeStaffSelection $selection): Result
	{
		return $this->walk(
			$selection,
			self::STAFF_LIMIT,
			new Error(
				'The staff of an office may not exceed ' . self::STAFF_LIMIT . ' people',
				OfficeEmployeeErrorCode::TOO_MANY,
				['limit' => self::STAFF_LIMIT],
			),
		);
	}

	/**
	 * @return Result data of a success: {userIds: int[]}
	 */
	public function expandForOffice(OfficeStaffSelection $selection): Result
	{
		return $this->walk(
			$selection,
			self::EXPANSION_CEILING,
			new Error(
				'The selection may not expand into more than ' . self::EXPANSION_CEILING . ' people',
				OfficeEmployeeErrorCode::SELECTION_TOO_LARGE,
				['limit' => self::EXPANSION_CEILING],
			),
		);
	}

	/**
	 * Without a target office this is the plan of a new office: everyone is added and no limit is
	 * checked, the expansion has held the staff to it already.
	 *
	 * @param int[] $userIds the expanded selection
	 * @param int[] $removedUserIds
	 * @return Result data of a success: {plan: OfficeStaffPlan}
	 */
	public function planFor(array $userIds, int $targetOfficeId = 0, array $removedUserIds = []): Result
	{
		$result = new Result();

		$userIds = IdNormalizer::normalize($userIds);
		$removedUserIds = IdNormalizer::normalize($removedUserIds);
		$isEdit = $targetOfficeId > 0;

		$lookedUpIds = $isEdit ? IdNormalizer::normalize([...$userIds, ...$removedUserIds]) : $userIds;
		$offices = $this->findOffices($lookedUpIds);
		$removed = array_flip($removedUserIds);

		$addedUserIds = [];
		$moves = [];

		foreach ($userIds as $userId)
		{
			$officeId = $offices[$userId] ?? 0;

			if (isset($removed[$userId]) || ($isEdit && $officeId === $targetOfficeId))
			{
				continue;
			}

			$addedUserIds[] = $userId;

			if ($officeId > 0)
			{
				$moves[$userId] = $officeId;
			}
		}

		if ($isEdit && count($addedUserIds) > self::STAFF_LIMIT)
		{
			return $result->addError(new Error(
				'No more than ' . self::STAFF_LIMIT . ' people may join the staff of an office at once',
				OfficeEmployeeErrorCode::TOO_MANY,
				['limit' => self::STAFF_LIMIT],
			));
		}

		return $result->setData([
			'plan' => new OfficeStaffPlan(
				userIds: $userIds,
				total: count($userIds),
				moves: $moves,
				fingerprint: $this->fingerprint($moves),
				addedUserIds: $addedUserIds,
				removedMemberIds: $isEdit ? $this->findMembers($removedUserIds, $offices, $targetOfficeId) : [],
			),
		]);
	}

	/**
	 * The walk stops on the first person over the ceiling. Everyone is sent to the filter once, however
	 * many chunks repeat them.
	 *
	 * @return Result data of a success: {userIds: int[]}
	 */
	private function walk(OfficeStaffSelection $selection, int $ceiling, Error $overflow): Result
	{
		$result = new Result();

		[$flatNodeIds, $deepNodeIds] = $this->dropNestedNodes($selection->flatNodeIds, $selection->deepNodeIds);

		$seen = [];
		$checked = [];

		$chunks = $this->employeeSetResolver->stream($selection->userIds, $flatNodeIds, $deepNodeIds);

		foreach ($chunks as $chunk)
		{
			$fresh = [];

			foreach ($chunk as $userId)
			{
				if (!isset($checked[$userId]))
				{
					$checked[$userId] = true;
					$fresh[] = $userId;
				}
			}

			if ($fresh === [])
			{
				continue;
			}

			foreach ($this->employeeEligibility->filter($fresh) as $userId)
			{
				$seen[$userId] = true;

				if (count($seen) > $ceiling)
				{
					return $result->addError($overflow);
				}
			}
		}

		return $result->setData(['userIds' => array_keys($seen)]);
	}

	/**
	 * The ids of the nodes above the given one, the node itself excluded.
	 *
	 * @return int[]
	 */
	protected function findAncestorNodeIds(int $nodeId): array
	{
		$parents = Container::getNodeService()->findParentsByNodeId(
			$nodeId,
			[NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
			DepthLevel::FULL,
		);

		if ($parents === null)
		{
			return [];
		}

		return array_values(array_diff(IdNormalizer::normalize($parents->getIds()), [$nodeId]));
	}

	/**
	 * A node inside a chosen deep node is walked by that node already, and so is a flat node chosen deep
	 * as well.
	 *
	 * @param int[] $flatNodeIds
	 * @param int[] $deepNodeIds
	 * @return array{0: int[], 1: int[]}
	 */
	private function dropNestedNodes(array $flatNodeIds, array $deepNodeIds): array
	{
		$flatNodeIds = IdNormalizer::normalize($flatNodeIds);
		$deepNodeIds = IdNormalizer::normalize($deepNodeIds);

		if ($deepNodeIds === [] || !Loader::includeModule('humanresources'))
		{
			return [$flatNodeIds, $deepNodeIds];
		}

		$nested = [];

		foreach (IdNormalizer::normalize([...$deepNodeIds, ...$flatNodeIds]) as $nodeId)
		{
			if (array_intersect($this->findAncestorNodeIds($nodeId), $deepNodeIds) !== [])
			{
				$nested[] = $nodeId;
			}
		}

		$deepNodeIds = array_values(array_diff($deepNodeIds, $nested));
		$flatNodeIds = array_values(array_diff($flatNodeIds, $nested, $deepNodeIds));

		return [$flatNodeIds, $deepNodeIds];
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int> user id => the office they are assigned to right now
	 */
	private function findOffices(array $userIds): array
	{
		$offices = [];

		foreach (array_chunk($userIds, OfficeEmployeeRepository::WRITE_CHUNK_SIZE) as $chunk)
		{
			$offices += $this->officeEmployeeRepository->getOfficeIdsByUserIds($chunk);
		}

		return $offices;
	}

	/**
	 * @param int[] $userIds
	 * @param array<int, int> $offices user id => the office they are assigned to right now
	 * @return int[] ascending
	 */
	private function findMembers(array $userIds, array $offices, int $officeId): array
	{
		$members = array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => ($offices[$userId] ?? 0) === $officeId,
		));
		sort($members);

		return $members;
	}

	/**
	 * @param array<int, int> $moves
	 */
	private function fingerprint(array $moves): string
	{
		if ($moves === [])
		{
			return '';
		}

		ksort($moves);

		$pairs = [];

		foreach ($moves as $userId => $officeId)
		{
			$pairs[] = $userId . ':' . $officeId;
		}

		return sha1(implode(',', $pairs));
	}
}
