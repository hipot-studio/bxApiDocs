<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Crm\DealTable;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\Date;

final class ClosedDealsCalculator
{
	private const USER_ID_LIMIT = 100;

	public function __construct(
		private readonly ?Connection $connection = null,
	)
	{
	}

	/** @return array<int, array{total: int, bestMonth: int}> */
	public function calculate(Date $from, Date $to, array $userIds): array
	{
		if ($from->format('Y-m-d') >= $to->format('Y-m-d'))
		{
			throw new \InvalidArgumentException('The start date must be before the end date');
		}

		foreach ($userIds as $userId)
		{
			if (!is_int($userId) || $userId <= 0)
			{
				throw new \InvalidArgumentException('User IDs must be positive integers');
			}
		}

		$userIds = array_values(array_unique($userIds));
		sort($userIds, SORT_NUMERIC);
		if (count($userIds) > self::USER_ID_LIMIT)
		{
			throw new \InvalidArgumentException('The user batch exceeds the supported limit');
		}

		if ($userIds === [])
		{
			return [];
		}

		$connection = $this->connection ?? Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$metrics = [];
		foreach ($userIds as $userId)
		{
			$monthlyTotals = $this->readUserMonths(
				$connection,
				$this->buildUserMonthsQuery($from, $to, $userId, $sqlHelper),
			);
			$metrics[$userId] = $this->summarize($monthlyTotals);
		}

		return $metrics;
	}

	private function buildUserMonthsQuery(Date $from, Date $to, int $userId, SqlHelper $sqlHelper): Query
	{
		return (new Query($this->getDealOwnerEntity()))
			->setCustomBaseTableAlias('d')
			->setSelect([
				new ExpressionField('MONTH', $this->monthExpression($sqlHelper), 'MOVED_TIME'),
				new ExpressionField('CNT', 'COUNT(*)'),
			])
			->where('ASSIGNED_BY_ID', $userId)
			->where('STAGE_SEMANTIC_ID', PhaseSemantics::SUCCESS)
			->where('MOVED_TIME', '>=', $from)
			->where('MOVED_TIME', '<', $to)
			->setGroup(['MONTH'])
		;
	}

	/** @return array<int, int> month => closed deals */
	private function readUserMonths(Connection $connection, Query $query): array
	{
		$monthlyTotals = [];
		$rows = $connection->query($query->getQuery());
		while ($row = $rows->fetch())
		{
			$month = $this->parseMonth($row['MONTH'] ?? null);
			$count = $row['CNT'] ?? null;
			if (
				!(is_int($count) && $count > 0)
				&& !(is_string($count) && preg_match('/^[1-9][0-9]*$/D', $count) === 1)
			)
			{
				throw new \UnexpectedValueException('The deals query returned an invalid deal count');
			}

			$monthlyTotals[$month] = ($monthlyTotals[$month] ?? 0) + (int)$count;
		}

		return $monthlyTotals;
	}

	private function parseMonth(mixed $value): int
	{
		if (
			(!is_string($value) && !is_int($value))
			|| preg_match('/^(0?[1-9]|1[0-2])$/D', (string)$value) !== 1
		)
		{
			throw new \UnexpectedValueException('The deals query returned an invalid month');
		}

		return (int)$value;
	}

	/**
	 * @param array<int, int> $monthlyTotals month => closed deals
	 * @return array{total: int, bestMonth: int}
	 */
	private function summarize(array $monthlyTotals): array
	{
		$bestMonth = 0;
		$bestMonthTotal = 0;
		foreach ($monthlyTotals as $month => $total)
		{
			if ($total > $bestMonthTotal || ($total === $bestMonthTotal && $month > $bestMonth))
			{
				$bestMonthTotal = $total;
				$bestMonth = $month;
			}
		}

		return ['total' => array_sum($monthlyTotals), 'bestMonth' => $bestMonth];
	}

	private function monthExpression(SqlHelper $sqlHelper): string
	{
		$expression = str_replace(
			'%s',
			'#FIELD#',
			$sqlHelper->formatDate('MM', '%s'),
		);
		$expression = $this->escapeExpression($expression);

		return str_replace('#FIELD#', '%s', $expression);
	}

	private function escapeExpression(string $sql): string
	{
		return str_replace('%', '%%', $sql);
	}

	private function getDealOwnerEntity(): Entity
	{
		static $entity = null;
		if ($entity === null)
		{
			$entity = Entity::compileEntity(
				'AnnualSummaryClosedDealOwner',
				[
					'ID' => ['data_type' => 'integer', 'primary' => true],
					'ASSIGNED_BY_ID' => ['data_type' => 'integer'],
					'STAGE_SEMANTIC_ID' => ['data_type' => 'string'],
					'MOVED_TIME' => ['data_type' => 'datetime'],
				],
				[
					'table_name' => DealTable::getTableName(),
					'namespace' => __NAMESPACE__,
				],
			);
		}

		return $entity;
	}
}
