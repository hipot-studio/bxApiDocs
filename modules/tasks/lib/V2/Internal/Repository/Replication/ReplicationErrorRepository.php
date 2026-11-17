<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Replication;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Internals\SystemLogTable;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodBucketer;

/**
 * Чтение ошибок постановки из `b_tasks_syslog` по шаблону в пределах периода (P5.T1, Q-4).
 *
 * Индикатор сбоя = наличие ≥1 записи `TYPE_ERROR` (ENTITY_TYPE_TEMPLATE) в выбранном
 * периоде; гаснет, если последняя по времени запись — не ошибка (успешная постановка
 * после сбоя). В v1 без классификации категорий (AC-S-048) — минимальный факт
 * «постановка не сработала»; причина — в логе шаблона.
 *
 * Групповая выборка по templateIds (не N+1) по индексу `ix_tasks_syslog_etei`.
 */
class ReplicationErrorRepository
{
	public function __construct(
		private readonly PeriodBucketer $bucketer = new PeriodBucketer(),
		private readonly InstanceBucketExpressionBuilder $bucketExpressionBuilder = new InstanceBucketExpressionBuilder(),
	)
	{
	}

	/**
	 * Есть ли активный индикатор сбоя по каждому шаблону в периоде.
	 *
	 * @param int[] $templateIds
	 * @return array<int, bool> [templateId => errorFlag]
	 */
	public function hasErrorInPeriod(array $templateIds, int $from, int $to): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);
		if ($templateIds === [] || $to < $from)
		{
			return [];
		}

		$rows = SystemLogTable::query()
			->setSelect(['ENTITY_ID', 'TYPE', 'CREATED_DATE'])
			->where('ENTITY_TYPE', SystemLogTable::ENTITY_TYPE_TEMPLATE)
			->whereIn('ENTITY_ID', $templateIds)
			->where('CREATED_DATE', '>=', DateTime::createFromTimestamp($from))
			->where('CREATED_DATE', '<=', DateTime::createFromTimestamp($to))
			->addOrder('CREATED_DATE', 'DESC')
			->addOrder('ID', 'DESC')
			->exec()
			->fetchAll()
		;

		// Первая (самая свежая) запись на шаблон определяет индикатор: ошибка гаснет,
		// если последняя запись в периоде — не TYPE_ERROR (успех после сбоя).
		$latestTypeByTemplate = [];
		foreach ($rows as $row)
		{
			$templateId = (int)$row['ENTITY_ID'];
			if (!array_key_exists($templateId, $latestTypeByTemplate))
			{
				$latestTypeByTemplate[$templateId] = (int)$row['TYPE'];
			}
		}

		$result = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			$result[$templateId] =
				($latestTypeByTemplate[$templateId] ?? null) === SystemLogTable::TYPE_ERROR
			;
		}

		return $result;
	}

	/**
	 * Число записей `TYPE_ERROR` по шаблону в периоде — для error-счётчика бакетов DTO-S2.
	 *
	 * @param int[] $templateIds
	 * @return array<int, int> [templateId => errorCount]
	 */
	public function countErrorsInPeriod(array $templateIds, int $from, int $to): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);
		if ($templateIds === [] || $to < $from)
		{
			return [];
		}

		$rows = SystemLogTable::query()
			->setSelect(['ENTITY_ID', 'CNT'])
			->registerRuntimeField(
				'CNT',
				new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(%s)', 'ID'),
			)
			->where('ENTITY_TYPE', SystemLogTable::ENTITY_TYPE_TEMPLATE)
			->where('TYPE', SystemLogTable::TYPE_ERROR)
			->whereIn('ENTITY_ID', $templateIds)
			->where('CREATED_DATE', '>=', DateTime::createFromTimestamp($from))
			->where('CREATED_DATE', '<=', DateTime::createFromTimestamp($to))
			->addGroup('ENTITY_ID')
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['ENTITY_ID']] = (int)$row['CNT'];
		}

		return $result;
	}

	/**
	 * Error-события постановки в окне — по одной записи `TYPE_ERROR` на сбой, `when` =
	 * `CREATED_DATE` (индекс `ix_tasks_syslog_etei`). В отличие от агрегатов выше отдаёт
	 * сами события: agenda подмешивает их в поток как элементы `status=error` (P2.T3).
	 *
	 * @param int[] $templateIds
	 * @param array{ts:int,id:int}|null $cursor
	 * @return array<int, array{errorId:int, templateId:int, when:int}> по возрастанию времени
	 */
	public function getErrors(
		array $templateIds,
		int $from,
		int $to,
		?array $cursor = null,
		int $limit = 1,
	): array
	{
		return $this->fetchErrors($templateIds, $from, $to, $cursor, $limit, false);
	}

	/**
	 * Страница ошибок в обратном хронологическом порядке для прошлой части agenda.
	 *
	 * @param int[] $templateIds
	 * @param array{ts:int,id:int}|null $cursor
	 * @return array<int, array{errorId:int, templateId:int, when:int}>
	 */
	public function getErrorsBefore(
		array $templateIds,
		int $from,
		int $to,
		?array $cursor,
		int $limit,
	): array
	{
		return $this->fetchErrors($templateIds, $from, $to, $cursor, $limit, true);
	}

	/**
	 * @param int[] $templateIds
	 * @param array{ts:int,id:int}|null $cursor
	 * @return array<int, array{errorId:int, templateId:int, when:int}>
	 */
	private function fetchErrors(
		array $templateIds,
		int $from,
		int $to,
		?array $cursor,
		int $limit,
		bool $descending,
	): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);
		if ($templateIds === [] || $to < $from || $limit < 1)
		{
			return [];
		}

		$query = SystemLogTable::query()
			->setSelect(['ID', 'ENTITY_ID', 'CREATED_DATE'])
			->where('ENTITY_TYPE', SystemLogTable::ENTITY_TYPE_TEMPLATE)
			->where('TYPE', SystemLogTable::TYPE_ERROR)
			->whereIn('ENTITY_ID', $templateIds)
			->where('CREATED_DATE', '>=', DateTime::createFromTimestamp($from))
			->where('CREATED_DATE', '<=', DateTime::createFromTimestamp($to))
			->addOrder('CREATED_DATE', $descending ? 'DESC' : 'ASC')
			->addOrder('ID', $descending ? 'DESC' : 'ASC')
			->setLimit($limit)
		;
		if ($cursor !== null)
		{
			$cursorDate = DateTime::createFromTimestamp($cursor['ts']);
			$query->where(
				Query::filter()
					->logic(ConditionTree::LOGIC_OR)
					->where('CREATED_DATE', $descending ? '<' : '>', $cursorDate)
					->where(
						Query::filter()
							->where('CREATED_DATE', $cursorDate)
							->where('ID', $descending ? '<' : '>', $cursor['id'])
					)
			);
		}

		$rows = $query->exec()->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$createdDate = $row['CREATED_DATE'] ?? null;
			if (!$createdDate instanceof DateTime)
			{
				continue;
			}

			$result[] = [
				'errorId' => (int)$row['ID'],
				'templateId' => (int)$row['ENTITY_ID'],
				'when' => $createdDate->getTimestamp(),
			];
		}

		return $result;
	}

	/**
	 * Число ошибок по шаблону и бакету — для error-счётчика бакетов DTO-S2 (hover-детализация).
	 * Бакетизация в поясе просматривающего, как факт/план.
	 *
	 * @param int[] $templateIds
	 * @return array<int, array<string, int>> [templateId => [bucketKey => errorCount]]
	 */
	public function countErrorsByTemplateAndBucket(
		array $templateIds,
		int $from,
		int $to,
		string $scale,
		int $tzOffset,
	): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);
		if ($templateIds === [] || $to < $from)
		{
			return [];
		}

		$keys = $this->bucketer->buildBucketKeys($scale, $from, $to, $tzOffset);
		if ($keys === [])
		{
			return [];
		}

		$bucketField = $this->bucketExpressionBuilder->build(
			Application::getConnection()->getSqlHelper(),
			$scale,
			$keys,
			$tzOffset,
		);
		$rows = SystemLogTable::query()
			->registerRuntimeField($bucketField)
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(%s)', 'ID'))
			->setSelect(['ENTITY_ID', InstanceBucketExpressionBuilder::FIELD_NAME, 'CNT'])
			->where('ENTITY_TYPE', SystemLogTable::ENTITY_TYPE_TEMPLATE)
			->where('TYPE', SystemLogTable::TYPE_ERROR)
			->whereIn('ENTITY_ID', $templateIds)
			->where('CREATED_DATE', '>=', DateTime::createFromTimestamp($from))
			->where('CREATED_DATE', '<=', DateTime::createFromTimestamp($to))
			->addGroup('ENTITY_ID')
			->addGroup(InstanceBucketExpressionBuilder::FIELD_NAME)
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$templateId = (int)$row['ENTITY_ID'];
			$key = (string)($row[InstanceBucketExpressionBuilder::FIELD_NAME] ?? '');
			if ($templateId <= 0 || $key === '')
			{
				continue;
			}

			$result[$templateId][$key] = (int)($row['CNT'] ?? 0);
		}

		return $result;
	}
}
