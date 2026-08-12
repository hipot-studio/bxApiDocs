<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Owner\ProjectOwnerState;
use Bitrix\Socialnetwork\WorkgroupTable;

class ProjectOwnerRepository implements ProjectOwnerRepositoryInterface
{
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
		$query = UserToGroupTable::query()
			->setSelect(['USER_ID'])
			->where($this->buildCandidateSelectionFilter($projectId, $currentOwnerId))
			->setOrder($this->getCandidateSelectionOrder())
			->setLimit(1)
		;

		$row = $query->exec()->fetch();
		$userId = (int)($row['USER_ID'] ?? 0);

		return $userId > 0 ? $userId : null;
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
				->whereNull('TYPE')
			);
	}
}
