<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Params\Scrum;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\ProjectCounterFilterService;
use Bitrix\Socialnetwork\V2\Internal\Repository\FavoritesRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupFilterRepositoryInterface;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectRole;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\AbstractProjectFilter;
use Bitrix\Socialnetwork\V2\Public\Provider\Params\Project\FieldsEnum;

class ScrumGridFilter extends AbstractProjectFilter
{
	public function __construct(
		private readonly Options $filterOptions,
		private readonly array $filterFields,
		private readonly WorkgroupFilterRepositoryInterface $workgroupFilterRepository,
		private readonly FavoritesRepositoryInterface $favoritesRepository,
		private readonly ProjectCounterFilterService $projectCounterFilterService,
		private readonly int $currentUserId = 0,
	)
	{
	}

	public function prepareFilter(): ConditionTree
	{
		$rawFilter = $this->filterOptions->getFilter($this->filterFields);
		$mapped = $this->mapGridFilter($rawFilter);

		$tree = $this->buildConditionTree($mapped);
		$this->applyLegacyTextFilters($tree, $rawFilter);

		return $tree;
	}

	private function mapGridFilter(array $rawFilter): array
	{
		$allowed = array_flip($this->getAllowedFields());
		$result = [];
		$idSets = [];

		foreach ($rawFilter as $key => $value)
		{
			if ($value === '' || $value === null)
			{
				continue;
			}

			if ($key === 'OWNER' && is_string($value))
			{
				$ownerId = $this->extractUserIdFromEntitySelector($value);
				if ($ownerId > 0)
				{
					$result['=OWNER_ID'] = $ownerId;
				}
				continue;
			}

			if ($key === 'MEMBER' && is_string($value))
			{
				$memberId = $this->extractUserIdFromEntitySelector($value);
				if ($memberId > 0)
				{
					$result['=MEMBERS.USER_ID'] = $memberId;
					$result['@MEMBERS.ROLE'] = ProjectRole::memberValues();
				}
				continue;
			}

			if ($key === 'FAVORITES' && $value === 'Y')
			{
				$idSets[] = $this->currentUserId > 0
					? $this->loadFavoriteGroupIds($this->currentUserId)
					: []
				;
				continue;
			}

			if ($key === 'TAG' && is_string($value) && $value !== '')
			{
				$idSets[] = $this->workgroupFilterRepository->getGroupIdsByTag($value);
				continue;
			}

			if ($key === 'EXTRANET' && $value === 'Y')
			{
				$idSets[] = $this->workgroupFilterRepository->getExtranetGroupIds();
				continue;
			}

			if ($key === 'COUNTERS' && is_string($value) && $value !== '')
			{
				$idSets[] = $this->currentUserId > 0
					? $this->projectCounterFilterService->getGroupIdsByCounter($this->currentUserId, $value)
					: []
				;
				continue;
			}

			if ($key === 'FIND' || $key === 'NAME')
			{
				continue;
			}

			if (str_ends_with((string)$key, '_from') || str_ends_with((string)$key, '_to'))
			{
				continue;
			}

			[$operator, $ormField] = $this->extractOperator((string)$key);

			$enum = FieldsEnum::fromOrmField($ormField);
			if ($enum === null)
			{
				continue;
			}

			if (!isset($allowed[$enum->value]))
			{
				continue;
			}

			$result[$operator . $enum->toOrmField()] = $value;
		}

		$result = $this->applyIdSetIntersection($result, $idSets);

		return array_merge($result, $this->extractLegacyRangeFilters($rawFilter));
	}

	/**
	 * @return int[]
	 */
	private function loadFavoriteGroupIds(int $userId): array
	{
		return $this->favoritesRepository->getFavoriteGroupIds($userId);
	}
}
