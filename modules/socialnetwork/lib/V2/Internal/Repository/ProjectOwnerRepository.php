<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\Provider\CollabUserPolicyProvider;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Owner\ProjectOwnerState;
use Bitrix\Socialnetwork\WorkgroupTable;

class ProjectOwnerRepository implements ProjectOwnerRepositoryInterface
{
	private const CANDIDATE_PAGE_SIZE = 200;

	public function __construct(private readonly ?CollabUserPolicyProvider $userPolicyProvider = null)
	{
	}

	public function getOwnerState(int $projectId): ?ProjectOwnerState
	{
		if ($projectId <= 0)
		{
			return null;
		}

		$query = WorkgroupTable::query()
			->setSelect([
				'ID',
				'TYPE',
				'SITE_ID',
				'NAME',
				'OWNER_ID',
				'OWNER_ACTIVE' => 'WORKGROUP_OWNER.ACTIVE',
				'OWNER_RELATION_USER_ID' => 'OWNER_RELATION.USER_ID',
			])
			->where('ID', $projectId)
			->where('ACTIVE', 'Y')
		;
		$query->registerRuntimeField(
			(new Reference(
				name: 'OWNER_RELATION',
				referenceEntity: UserToGroupTable::class,
				referenceFilter: Join::on('this.ID', 'ref.GROUP_ID')
					->whereColumn('this.OWNER_ID', 'ref.USER_ID')
					->where('ref.ROLE', UserToGroupTable::ROLE_OWNER),
			))->configureJoinType(Join::TYPE_LEFT),
		);
		$this->applyProjectTypeFilter($query);

		$project = $query->exec()->fetch();
		if (!is_array($project))
		{
			return null;
		}

		return new ProjectOwnerState(
			projectId: (int)$project['ID'],
			ownerId: (int)($project['OWNER_ID'] ?? 0),
			ownerActive: (string)($project['OWNER_ACTIVE'] ?? ''),
			ownerRelationUserId: (int)($project['OWNER_RELATION_USER_ID'] ?? 0),
			projectFields: $project,
		);
	}

	public function findReplacementOwnerId(int $projectId, int $currentOwnerId): ?int
	{
		$requiresPolicy = false;
		if (!Loader::includeModule('extranet'))
		{
			$group = WorkgroupTable::query()->setSelect(['TYPE'])->where('ID', $projectId)->fetch();
			$requiresPolicy = ($group['TYPE'] ?? null) === Type::Collab->value;
		}

		$limit = $requiresPolicy ? self::CANDIDATE_PAGE_SIZE : 1;
		$query = UserToGroupTable::query()
			->setSelect(['USER_ID'])
			->where($this->buildCandidateSelectionFilter($projectId, $currentOwnerId))
			->setOrder($this->getCandidateSelectionOrder())
			->setLimit($limit)
		;

		$offset = 0;
		do
		{
			$rows = $query->setOffset($offset)->exec()->fetchAll();
			$userIds = array_map('intval', array_column($rows, 'USER_ID'));
			if ($requiresPolicy)
			{
				$classification = ($this->userPolicyProvider ?? CollabUserPolicyProvider::getInstance())->classify($userIds);
				$userIds = array_values(array_intersect($userIds, $classification['internal']));
			}
			if ($userIds !== [])
			{
				return $userIds[0] > 0 ? $userIds[0] : null;
			}
			$offset += $limit;
		}
		while ($requiresPolicy && count($rows) === $limit);

		return null;
	}

	protected function buildCandidateSelectionFilter(int $projectId, int $currentOwnerId): ConditionTree
	{
		$filter = Query::filter()
			->where('GROUP_ID', $projectId)
			->whereIn('ROLE', [
				UserToGroupTable::ROLE_MODERATOR,
				UserToGroupTable::ROLE_USER,
			])
			->where('USER.ACTIVE', 'Y')
		;

		if ($currentOwnerId > 0)
		{
			$filter->where('USER_ID', '!=', $currentOwnerId);
		}

		return $filter;
	}

	protected function getCandidateSelectionOrder(): array
	{
		return [
			'ROLE' => 'ASC',
			'DATE_CREATE' => 'ASC',
			'USER_ID' => 'ASC',
		];
	}

	private function applyProjectTypeFilter(Query $query): void
	{
		$query->where(
			Query::filter()
				->logic('or')
				->whereNot('TYPE', Type::Scrum->value)
				->whereNull('TYPE'),
			);
	}
}
