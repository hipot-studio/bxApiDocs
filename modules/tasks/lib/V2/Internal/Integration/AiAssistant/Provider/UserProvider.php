<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Search\Content;
use Bitrix\Main\UserIndexTable;
use Bitrix\Main\UserTable;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Member\SearchUsersDto;

class UserProvider
{
	private const LIMIT = 30;
	private const MIN_TOKEN_SIZE = 2;

	public function search(SearchUsersDto $dto): array
	{
		if (empty($dto->searchQueries))
		{
			return [];
		}

		$expandedSearchQueries = $this->expandSearchQueries($dto->searchQueries);
		if (empty($expandedSearchQueries))
		{
			return [];
		}

		$searchFilter = Query::filter()->logic('or');

		foreach ($expandedSearchQueries as $searchQuery)
		{
			$searchFilter
				->whereMatch(
					'USER_INDEX.SEARCH_USER_CONTENT',
					Helper::matchAgainstWildcard(
						Content::prepareStringToken($searchQuery), '*', self::MIN_TOKEN_SIZE,
					),
				)
			;
		}

		return
			UserTable::query()
				->setSelect(['ID', 'LAST_NAME', 'NAME', 'SECOND_NAME'])
				->where('ACTIVE', 'Y')
				->where($this->getIntranetUserFilter())
				->where($searchFilter)
				->registerRuntimeField(
					new Reference(
						'USER_INDEX',
						UserIndexTable::class,
						Join::on('this.ID', 'ref.USER_ID'),
						['join_type' => Join::TYPE_INNER],
					),
				)
				->setLimit(self::LIMIT)
				->exec()
				->fetchAll()
		;
	}

	private function expandSearchQueries(array $queries): array
	{
		$expandedQueries = [];

		foreach ($queries as $query)
		{
			$query = trim($query);

			$expandedQueries[] = $query;

			$words = preg_split('/\s+/', $query);
			if ($words === false)
			{
				continue;
			}

			foreach ($words as $word)
			{
				if (mb_strlen($word) >= self::MIN_TOKEN_SIZE)
				{
					$expandedQueries[] = $word;
				}
			}
		}

		return array_unique($expandedQueries);
	}

	// TODO: Use new API from humanresources instead
	private function getIntranetUserFilter(): ConditionTree
	{
		$conditionTree = Query::filter();

		if (!Loader::includeModule('intranet'))
		{
			return $conditionTree;
		}

		$emptySerializedValues = [serialize([]), serialize([0])];

		return
			$conditionTree
				->whereNotNull('UF_DEPARTMENT')
				->whereNotIn('UF_DEPARTMENT', $emptySerializedValues)
				->where(
					Query::filter()
						->logic('or')
						->whereNull('EXTERNAL_AUTH_ID')
						->whereNotIn('EXTERNAL_AUTH_ID', UserTable::getExternalUserTypes())
				)
		;
	}
}
