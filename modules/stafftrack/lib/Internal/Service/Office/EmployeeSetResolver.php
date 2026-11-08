<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Builder\Structure\Sort\NodeMemberSort;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\SortDirection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;
use Bitrix\StaffTrack\Dictionary\OfficeEmployeeErrorCode;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\IdNormalizer;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\SelectorEntityNormalizer;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;

/**
 * The codes of the entity selector turned into a stream of employees handed out in chunks, never as one
 * set: every employee of a large portal is thousands of people, and the write they feed goes in chunks
 * anyway.
 *
 * The three kinds of code do not answer alike. People named outright are checked in one query and the
 * ones who are no employees come back as rejections. Departments and every-employee are walked page by
 * page and reject nobody: the walk answers with those who pass it and says nothing about the rest.
 *
 * The walk is the thin part of the feature. Three of its properties are ours rather than the foreign
 * module's - the order by the identifier of the membership, the cursor instead of an offset and the
 * daily cache dropped - and losing any of them does not fail: it leaves people out of the set while the
 * call still answers with a success.
 *
 * What a test replaces is reached through the protected members: the page of members and the root node
 * of the structure.
 */
class EmployeeSetResolver
{
	/**
	 * The size of a chunk is a parameter and not the constant itself: the completeness of the walk is only
	 * provable on a set that does not fit one chunk, and a test proving it on five hundred people would
	 * never be written.
	 */
	public function __construct(
		protected readonly int $chunkSize = OfficeEmployeeRepository::WRITE_CHUNK_SIZE,
	)
	{
	}

	/**
	 * The set as it stands at the moment of the call: a snapshot, not a subscription, so a person taken on
	 * afterwards is not sent along (AC-017).
	 *
	 * @param array $entities codes of the entity selector
	 * @return Result data of a success: {
	 *     rejected: array<int, array{userId: int, code: string}>,
	 *     employees: \Generator<int, int[]>,
	 * }
	 */
	public function resolve(array $entities): Result
	{
		$result = new Result();

		[$userIds, $flatNodeIds, $recursiveNodeIds] = SelectorEntityNormalizer::normalize($entities);

		$rootNode = null;
		if (SelectorEntityNormalizer::containsAllEmployees($entities))
		{
			$rootNode = $this->getRootNode();
			if ($rootNode === null)
			{
				// An empty stream here would read as a success with nobody assigned, which is
				// indistinguishable from "everyone is assigned already" and untrue on both counts.
				return $result->addError(new Error(
					'The portal has no structure to take every employee from',
					OfficeEmployeeErrorCode::NO_STRUCTURE,
				));
			}
		}

		$userIds = IdNormalizer::normalize($userIds);
		$employeeIds = $this->keepEmployees($userIds);

		return $result->setData([
			'rejected' => array_map(
				static fn (int $userId): array => [
					'userId' => $userId,
					'code' => OfficeEmployeeErrorCode::NOT_FOUND,
				],
				array_values(array_diff($userIds, $employeeIds)),
			),
			'employees' => $this->streamEmployees($employeeIds, $flatNodeIds, $recursiveNodeIds, $rootNode),
		]);
	}

	/**
	 * The root node of the structure of the portal, or nothing at all on a portal whose structure was
	 * never converted.
	 */
	protected function getRootNode(): ?Node
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		$rootNode = Container::getNodeService()->getRootNode();

		return $rootNode !== null && $rootNode->id > 0 ? $rootNode : null;
	}

	/**
	 * One page of the members of the named nodes, continued past the membership the previous page ended
	 * on.
	 *
	 * @param int[] $nodeIds
	 * @return array<int, array{ID: int|string, ENTITY_ID: int|string}>
	 */
	protected function readMemberPage(
		array $nodeIds,
		DepthLevel $depthLevel,
		?NodeEntityType $nodeType,
		int $afterMemberId,
		int $limit,
	): array
	{
		return $this
			->prepareMemberPageQuery($nodeIds, $depthLevel, $nodeType, $afterMemberId, $limit)
			->fetchAll()
		;
	}

	/**
	 * The query of a page: assembled by the builder of the foreign module and finished here.
	 *
	 * The order is by the identifier of the membership - the only unique column of the selection, and the
	 * builder imposes no order of its own. The cursor is written on top of the assembled query, because
	 * the builder has no filter for "past the one given"; an offset would make the storage count up to it
	 * every page and would lose a neighbour of everyone who left the structure mid-walk. The daily cache
	 * of the builder is dropped, or the pages of one walk would be assembled out of answers of different
	 * freshness.
	 *
	 * Service records stay out by the default of the filter of members, and the default is stated here
	 * rather than relied upon silently. The builder is made anew for every page on purpose: prepareQuery()
	 * mutates the filters it was given.
	 *
	 * @param int[] $nodeIds
	 */
	protected function prepareMemberPageQuery(
		array $nodeIds,
		DepthLevel $depthLevel,
		?NodeEntityType $nodeType,
		int $afterMemberId,
		int $limit,
	): Query
	{
		$query = NodeMemberDataBuilder::createWithFilter(
			new NodeMemberFilter(
				entityType: MemberEntityType::USER,
				nodeFilter: new NodeFilter(
					idFilter: IdFilter::fromIds($nodeIds),
					entityTypeFilter: $nodeType === null ? null : NodeTypeFilter::fromNodeType($nodeType),
					depthLevel: $depthLevel,
				),
				active: true,
				withVirtualUsers: false,
			),
		)
			->setSelect(['ID', 'ENTITY_ID'])
			->setSort(new NodeMemberSort(id: SortDirection::Asc))
			->setLimit($limit)
			->setCacheTtl(0)
			->prepareQuery()
		;

		$query->where('ID', '>', $afterMemberId);

		return $query;
	}

	/**
	 * @param int[] $employeeIds
	 * @param int[] $flatNodeIds
	 * @param int[] $recursiveNodeIds
	 * @return \Generator<int, int[]>
	 */
	private function streamEmployees(
		array $employeeIds,
		array $flatNodeIds,
		array $recursiveNodeIds,
		?Node $rootNode,
	): \Generator
	{
		$buffer = $employeeIds;

		foreach ($this->cutChunks($buffer) as $chunk)
		{
			yield $chunk;
		}

		foreach ($this->walks($flatNodeIds, $recursiveNodeIds, $rootNode) as [$nodeIds, $depthLevel, $nodeType])
		{
			$afterMemberId = 0;

			while (true)
			{
				$rows = $this->readMemberPage($nodeIds, $depthLevel, $nodeType, $afterMemberId, $this->chunkSize);
				if ($rows === [])
				{
					break;
				}

				$lastMemberId = $afterMemberId;

				foreach ($rows as $row)
				{
					$buffer[] = (int)$row['ENTITY_ID'];
					$lastMemberId = max($lastMemberId, (int)$row['ID']);
				}

				foreach ($this->cutChunks($buffer) as $chunk)
				{
					yield $chunk;
				}

				// A cursor that did not move would ask for the same page for ever.
				if (count($rows) < $this->chunkSize || $lastMemberId <= $afterMemberId)
				{
					break;
				}

				$afterMemberId = $lastMemberId;
			}
		}

		$tail = IdNormalizer::normalize($buffer);
		if ($tail !== [])
		{
			yield $tail;
		}
	}

	/**
	 * The walks a set of codes asks for. A person met by two of them costs a repetition between chunks and
	 * nothing more: the caller skips whoever is already assigned.
	 *
	 * @param int[] $flatNodeIds
	 * @param int[] $recursiveNodeIds
	 * @return array<int, array{0: int[], 1: DepthLevel, 2: ?NodeEntityType}>
	 */
	private function walks(array $flatNodeIds, array $recursiveNodeIds, ?Node $rootNode): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$walks = [];

		if ($flatNodeIds !== [])
		{
			$walks[] = [$flatNodeIds, DepthLevel::NONE, null];
		}

		if ($recursiveNodeIds !== [])
		{
			$walks[] = [$recursiveNodeIds, DepthLevel::FULL, null];
		}

		if ($rootNode !== null)
		{
			// Every employee is the root node with everything under it, and the walk is kept to the nodes
			// of the type of the root deliberately: a member of a team who is in no department stays out,
			// the same way a person outside the structure does (ADR, "Every employee").
			$walks[] = [[$rootNode->id], DepthLevel::FULL, $rootNode->type];
		}

		return $walks;
	}

	/**
	 * The chunks the buffer has grown long enough for, taken out of it.
	 *
	 * A person met twice within one chunk would break the unique key, and the batch answers a broken key
	 * with an exception rather than an error - the whole chunk would go back over one repetition. Two
	 * things repeat people: a person of two departments of the set, and the filter of the foreign module
	 * itself, which matches a member once per named node of their path. So a chunk is normalised before it
	 * leaves; between chunks a repetition costs nothing, and dropping it would mean holding the whole set
	 * in memory.
	 *
	 * @param int[] $buffer
	 * @return int[][]
	 */
	private function cutChunks(array &$buffer): array
	{
		$chunks = [];

		while (count($buffer) >= $this->chunkSize)
		{
			$chunk = IdNormalizer::normalize(array_splice($buffer, 0, $this->chunkSize));
			if ($chunk !== [])
			{
				$chunks[] = $chunk;
			}
		}

		return $chunks;
	}

	/**
	 * The named people that are employees of the company, in one query: a dismissed account and a record
	 * the portal keeps for itself are neither, and an identifier of nobody is not one either (Q-1).
	 *
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function keepEmployees(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where('ACTIVE', 'Y')
			->where('IS_REAL_USER', 'Y')
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}
}
