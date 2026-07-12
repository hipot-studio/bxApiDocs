<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Project;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectCollection;

interface ProjectRepositoryInterface
{
	public function getById(int $projectId): ?Project;

	/**
	 * @return string[]
	 */
	public function getSiteIds(int $projectId): array;

	public function getList(
		array $select = ['*'],
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		bool $withImage = false,
	): ProjectCollection;

	/**
	 * @param int[] $groupIds
	 * @return array<int, string[]>
	 */
	public function getTagsByGroupIds(array $groupIds): array;

	/**
	 * @param int[] $groupIds
	 * @return array<int, string>
	 */
	public function getRelationDates(array $groupIds, int $userId): array;

	/**
	 * @param int[] $groupIds
	 * @return array<int, string>
	 */
	public function getViewDates(array $groupIds, int $userId): array;

	/**
	 * @param string[] $types
	 * @return array<int, array{ID: string}>
	 */
	public function getGroupIdsByTypes(array $types, int $lastId, int $limit): array;

	/**
	 * @param int[] $projectIds
	 * @return array<int, bool>
	 */
	public function getHasCollabersBatch(array $projectIds): array;

	/**
	 * @return array{name: ?string, type: ?string}|null
	 */
	public function getNameAndType(int $projectId): ?array;

	public function getProjectsWithSummaryAgent(?int $offset = null, ?int $limit = null): ProjectCollection;
}
