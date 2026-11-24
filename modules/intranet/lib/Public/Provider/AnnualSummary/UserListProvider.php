<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\AnnualSummary;

use Bitrix\Intranet\Internal\Model\AnnualSummaryTable;
use Bitrix\Intranet\Public\Service\AnnualSummary\AdminAccess;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

final class UserListProvider
{
	public function getPage(int $limit = 20, int $offset = 0, array $filter = []): array
	{
		(new AdminAccess())->assertAllowed();

		$filter = [
			'ID' => is_string($filter['ID'] ?? null) || is_int($filter['ID'] ?? null)
				? trim((string)$filter['ID'])
				: '',
			'FIND' => is_string($filter['FIND'] ?? null) ? trim($filter['FIND']) : '',
			'LESS_THAN_FIVE' => ($filter['LESS_THAN_FIVE'] ?? null) === 'Y',
		];
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$totalCount = (int)$this->createUserQuery($filter)->queryCountTotal();
		$users = $this->createUserQuery($filter)
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE', 'EMAIL'])
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->setOffset($offset)
			->fetchAll()
		;
		$codesByUser = [];
		if ($users !== [])
		{
			$metrics = AnnualSummaryTable::query()
				->setSelect(['USER_ID', 'NAME'])
				->whereIn('USER_ID', array_unique(array_column($users, 'ID')))
				->exec()
			;
			while ($metric = $metrics->fetch())
			{
				$codesByUser[(int)$metric['USER_ID']][] = (string)$metric['NAME'];
			}
		}

		$rows = [];
		foreach ($users as $user)
		{
			$user['ID'] = (int)$user['ID'];
			foreach (['NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'TITLE', 'EMAIL'] as $field)
			{
				$user[$field] = (string)$user[$field];
			}
			$metricCodes = $codesByUser[$user['ID']] ?? [];
			sort($metricCodes, SORT_STRING);
			$rows[] = [
				'user' => $user,
				'metricCodes' => $metricCodes,
				'metricCount' => count($metricCodes),
			];
		}

		return ['totalCount' => $totalCount, 'rows' => $rows];
	}

	private function createUserQuery(array $filter): Query
	{
		$query = UserTable::query()
			->where('REAL_USER', 'expr', true)
			->where('ACTIVE', true)
			->where('DATE_REGISTER', '<', new DateTime('2026-10-01', 'Y-m-d'))
		;
		$find = $filter['FIND'];
		$idFilters = [$filter['ID']];
		if (preg_match('/^[0-9]+$/D', $find) === 1)
		{
			$idFilters[] = $find;
		}
		elseif ($find !== '')
		{
			$nameFilter = [];
			foreach (preg_split('/\s+/', $find, -1, PREG_SPLIT_NO_EMPTY) as $part)
			{
				$nameFilter[] = [
					'LOGIC' => 'OR',
					'%NAME' => $part,
					'%LAST_NAME' => $part,
					'%SECOND_NAME' => $part,
				];
			}
			$query->setFilter([$nameFilter]);
		}
		foreach ($idFilters as $id)
		{
			if ($id === '')
			{
				continue;
			}
			$id = ltrim($id, '0');
			$maxId = (string)PHP_INT_MAX;
			$isValidId = preg_match('/^[0-9]+$/D', $id) === 1
				&& (strlen($id) < strlen($maxId) || (strlen($id) === strlen($maxId) && strcmp($id, $maxId) <= 0));
			$query->where('ID', $isValidId ? (int)$id : 0);
		}
		if ($filter['LESS_THAN_FIVE'])
		{
			$query->whereNotIn('ID', AnnualSummaryTable::query()
				->setSelect(['USER_ID'])
				->registerRuntimeField(Query::expr('METRIC_COUNT')->count('ID'))
				->setGroup(['USER_ID'])
				->having('METRIC_COUNT', '>=', 5),
			);
		}

		return $query;
	}
}
