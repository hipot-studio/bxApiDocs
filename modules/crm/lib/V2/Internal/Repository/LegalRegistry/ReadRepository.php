<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\LegalRegistry;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\{
	DocumentTable, EventTable, FactTable, SourceTable, SubmissionTable, SubjectTable, UsageTable, VersionTable,
};
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\SubmissionService;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormSourceGateway;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\{CatalogDocument, FormUsage, RegistryResult};
use Bitrix\Main\ORM\Fields\{ExpressionField, IntegerField};
use Bitrix\Main\ORM\Query\Filter\{Condition, ConditionTree};
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Provider\Params\GridParams;

/** Internal queries apply staff ACL before counts, keyset paging and hydration. */
final class ReadRepository
{
	public function getFacts(int $userId, GridParams $params): RegistryResult
	{
		try
		{
			$access = RegistryAccess::forUser($userId);
			if ($userId <= 0 || !$access->canReadForm()) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			[$filter, $cursor, $direction, $limit] = self::grid($params,
				['ID', 'DOCUMENT_ID', 'VERSION_ID', 'SUBMISSION_ID', 'SUBJECT_ID']);
			$query = FactTable::query()->setSelect(['*'])->where($filter);
			$count = (int)$this->factScope($access, clone $query, true)->queryCountTotal();
			$query = $this->factScope($access, $query);
			if ($cursor !== null) { $query->where('ID', $direction === 'ASC' ? '>' : '<', $cursor); }
			$rows = $query->setOrder(['ID' => $direction])->setLimit($limit + 1)->fetchAll();
			$more = count($rows) > $limit;
			if ($more) { array_pop($rows); }
			$facts = $this->hydrate($rows); $ids = array_map(static fn($fact): int => $fact->id, $facts);

			return (new RegistryResult())->setFacts($facts)->setFactIds($ids)->setTotalCount($count)
				->setNextCursor($more ? $ids[array_key_last($ids)] : null)
			;
		}
		catch (\InvalidArgumentException|\Bitrix\Main\ArgumentException)
		{
			return SubmissionService::error('INVALID_PAYLOAD');
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	public function getFact(int $userId, int $id): RegistryResult
	{
		try
		{
			$access = RegistryAccess::forUser($userId);
			if ($userId <= 0 || $id <= 0 || $id > 2147483647 || !$access->canReadForm())
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			$row = $this->factScope($access, FactTable::query()->setSelect(['*'])->where('ID', $id))->fetch();
			if (!$row) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }

			return (new RegistryResult())->setFacts($this->hydrate([$row]))->setFactIds([$id]);
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	public function getPending(int $userId, ?int $cursor, int $limit): RegistryResult
	{
		try
		{
			$access = RegistryAccess::forUser($userId);
			if ($userId <= 0 || !$access->canReadForm() || !Container::getInstance()->getUserPermissions($userId)->isAdmin())
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			if ($limit < 1 || $limit > 100 || ($cursor !== null && ($cursor <= 0 || $cursor > 2147483647)))
			{
				return SubmissionService::error('INVALID_PAYLOAD');
			}
			$query = SubmissionTable::query()->setSelect(['ID', 'SOURCE_ID', 'STATE', 'SERVER_RECEIVED_AT', 'ERROR_CODE'])
				->where('STATE', 'pending')
			;
			$count = (int)(clone $query)->queryCountTotal();
			if ($cursor !== null) { $query->where('ID', '>', $cursor); }
			$rows = $query->setOrder(['ID' => 'ASC'])->setLimit($limit + 1)->fetchAll();
			$more = count($rows) > $limit; if ($more) { array_pop($rows); }
			$sources = self::rowsById(SourceTable::class, array_column($rows, 'SOURCE_ID')); $pending = [];
			foreach ($rows as $row)
			{
				$source = $sources[$row['SOURCE_ID']];
				$pending[] = ['id' => (int)$row['ID'], 'sourceId' => (int)$source['ID'], 'formId' => (int)$source['FORM_ID'],
					'sourceName' => $source['NAME_SNAPSHOT'], 'state' => $row['STATE'],
					'serverReceivedAt' => SubmissionService::immutableDate($row['SERVER_RECEIVED_AT']),
					'errorCode' => $row['ERROR_CODE']];
			}

			return (new RegistryResult())->setPending($pending)->setTotalCount($count)
				->setNextCursor($more ? $pending[array_key_last($pending)]['id'] : null)
			;
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	public function getDocument(int $userId, int $id): RegistryResult
	{
		try
		{
			if ($userId <= 0 || $id <= 0 || $id > 2147483647 || !RegistryAccess::forUser($userId)->canReadForm())
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			$row = DocumentTable::getById($id)->fetch();
			if (!$row) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			$document = $this->documents([$row])[0];

			return (new RegistryResult())->setDocument($document)->setVersions($document->versions);
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	public function getDocuments(int $userId, GridParams $params): RegistryResult
	{
		try
		{
			if ($userId <= 0 || !RegistryAccess::forUser($userId)->canReadForm())
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			[$filter, $cursor, $direction, $limit] = self::grid($params, ['ID']);
			$query = DocumentTable::query()->setSelect(['*'])->where($filter);
			$count = (int)(clone $query)->queryCountTotal();
			if ($cursor !== null) { $query->where('ID', $direction === 'ASC' ? '>' : '<', $cursor); }
			$rows = $query->setOrder(['ID' => $direction])->setLimit($limit + 1)->fetchAll();
			$more = count($rows) > $limit; if ($more) { array_pop($rows); }

			return (new RegistryResult())->setDocuments($this->documents($rows))->setTotalCount($count)
				->setNextCursor($more ? (int)$rows[array_key_last($rows)]['ID'] : null)
			;
		}
		catch (\InvalidArgumentException|\Bitrix\Main\ArgumentException)
		{
			return SubmissionService::error('INVALID_PAYLOAD');
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	public function getUsage(int $userId, int $documentId, ?int $cursor, int $limit): RegistryResult
	{
		try
		{
			if ($userId <= 0 || $documentId <= 0 || $documentId > 2147483647
				|| !RegistryAccess::forUser($userId)->canReadForm())
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			if ($limit < 1 || $limit > 100 || ($cursor !== null && ($cursor <= 0 || $cursor > 2147483647)))
			{
				return SubmissionService::error('INVALID_PAYLOAD');
			}
			if (!DocumentTable::getById($documentId)->fetch()) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			$exists = new ExpressionField('LEGAL_USAGE_EXISTS',
				'EXISTS(SELECT 1 FROM b_crm_legal_usage u WHERE u.SOURCE_ID=%s AND u.DOCUMENT_ID=' . $documentId . ')', ['ID']);
			$exists->configureValueType(IntegerField::class);
			$query = SourceTable::query()->registerRuntimeField($exists)->setSelect(['*'])->where('LEGAL_USAGE_EXISTS', 1);
			$count = (int)(clone $query)->queryCountTotal();
			if ($cursor !== null) { $query->where('ID', '>', $cursor); }
			$rows = $query->setOrder(['ID' => 'ASC'])->setLimit($limit + 1)->fetchAll();
			$more = count($rows) > $limit; if ($more) { array_pop($rows); }
			$history = []; $result = [];
			if ($rows !== [])
			{
				$usages = UsageTable::getList([
					'filter' => ['@SOURCE_ID' => array_column($rows, 'ID'), '=DOCUMENT_ID' => $documentId],
					'order' => ['ID' => 'ASC']])->fetchAll();
				foreach ($usages as $usage) { $history[$usage['SOURCE_ID']][] = $usage; }
				$current = FormSourceGateway::getInstance()->readCurrentForms(array_map('intval', array_column($rows, 'FORM_ID')));
				foreach ($rows as $source)
				{
					$versions = []; $intervals = [];
					foreach ($history[$source['ID']] as $usage)
					{
						$version = (int)$usage['VERSION_ID']; $versions[$version] = $version;
						$intervals[] = ['versionId' => $version, 'revision' => (int)$usage['REVISION'],
							'checked' => $usage['CHECKED'] === 'Y', 'required' => $usage['REQUIRED'] === 'Y',
							'startsAt' => SubmissionService::immutableDate($usage['DATE_START']),
							'endsAt' => $usage['DATE_END'] === null ? null : SubmissionService::immutableDate($usage['DATE_END']),
							'formNameSnapshot' => $usage['FORM_NAME_SNAPSHOT']];
					}
					$form = $current[$source['FORM_ID']];
					$result[] = new FormUsage((int)$source['ID'], (int)$source['FORM_ID'], $documentId,
						$form['exists'] ? $form['name'] : $source['NAME_SNAPSHOT'], !$form['exists'], $form['url'],
						$source['STATE'], (int)$source['REVISION'], array_values($versions), $intervals);
				}
			}

			return (new RegistryResult())->setUsage($result)->setTotalCount($count)
				->setNextCursor($more ? (int)$rows[array_key_last($rows)]['ID'] : null)
			;
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	/** Full version histories are batch loaded, without a separate relation paging contract. */
	private function documents(array $rows): array
	{
		if ($rows === []) { return []; }
		$versions = VersionTable::getList(['filter' => ['@DOCUMENT_ID' => array_column($rows, 'ID')],
			'order' => ['NUMBER' => 'ASC', 'ID' => 'ASC']])->fetchAll();
		$grouped = [];
		foreach ($versions as $version) { $grouped[$version['DOCUMENT_ID']][] = CatalogRepository::mapVersion($version); }
		$result = [];
		foreach ($rows as $row)
		{
			$result[] = new CatalogDocument((int)$row['ID'], $row['NAME'], $row['ARCHIVED'] === 'Y',
				$row['ORIGIN_MODULE'], $row['ORIGIN_ID'] === null ? null : (int)$row['ORIGIN_ID'],
				SubmissionService::immutableDate($row['CREATED_AT']), (int)$row['CREATED_BY'], $grouped[$row['ID']] ?? []);
		}

		return $result;
	}

	/** Both access paths use the same materialized native subject ACL before counting or paging. */
	private function factScope(RegistryAccess $access, Query $query, bool $count = false): Query
	{
		$relation = $this->allowedSubjects($access, $query);
		if ($count) { return $query->whereIn('SUBJECT_ID', new SqlExpression($relation)); }
		// CASE keeps membership scalar: the optimizer can stop an ordered Fact scan at the page limit.
		$membership = new ExpressionField('LEGAL_FACT_ALLOWED',
			'CASE WHEN %s IN (' . str_replace('%', '%%', $relation) . ') THEN 1 ELSE 0 END', ['SUBJECT_ID']);
		$membership->configureValueType(IntegerField::class);

		return $query->registerRuntimeField($membership)->where('LEGAL_FACT_ALLOWED', 1);
	}

	private function allowedSubjects(RegistryAccess $access, Query $query): string
	{
		$subjects = $access->applySubjectReadScope(SubjectTable::query()->setSelect(['ID']), 'ID')->setGroup(['ID']);
		// GROUP BY makes the portable derived relation materializable instead of moving ACL into each Fact row.
		$helper = $query->getEntity()->getConnection()->getSqlHelper();
		$id = $helper->quote('ID'); $alias = $helper->quote('legal_read_subjects');

		return 'SELECT ' . $alias . '.' . $id . ' FROM (' . $subjects->getQuery() . ') ' . $alias;
	}

	/** Rebuild a small allowlisted filter; caller ConditionTree SQL, expressions and selects are never executed. */
	public static function grid(GridParams $params, array $allowed): array
	{
		$limit = $params->getLimit(); $sort = $params->getSort() ?? ['ID' => 'ASC'];
		if ($limit < 1 || $limit > 100 || $params->getOffset() !== 0 || $params->getSelect() !== null
			|| count($sort) !== 1 || !isset($sort['ID']) || !in_array($sort['ID'], ['ASC', 'DESC'], true))
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}
		$filter = new ConditionTree(); $cursor = null; $tree = $params->getFilter();
		if ($tree !== null)
		{
			if ($tree->logic() !== 'and' || (new \ReflectionProperty($tree, 'isNegative'))->getValue($tree)
				|| count($tree->getConditions()) > 20) { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
			foreach ($tree->getConditions() as $condition)
			{
				if (!$condition instanceof Condition || !in_array($condition->getColumn(), $allowed, true)
					|| !in_array($condition->getOperator(), ['=', '>', '<'], true)
					|| !is_int($condition->getValue()) || $condition->getValue() <= 0 || $condition->getValue() > 2147483647)
				{
					throw new \InvalidArgumentException('INVALID_PAYLOAD');
				}
				$field = $condition->getColumn(); $op = $condition->getOperator(); $value = $condition->getValue();
				if ($field === 'ID' && $op !== '=')
				{
					if ($cursor !== null || $op !== ($sort['ID'] === 'ASC' ? '>' : '<'))
					{
						throw new \InvalidArgumentException('INVALID_PAYLOAD');
					}
					$cursor = $value;
				}
				elseif ($op !== '=') { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
				else { $filter->where($field, $value); }
			}
		}

		return [$filter, $cursor, $sort['ID'], $limit];
	}

	private function hydrate(array $rows): array
	{
		if ($rows === []) { return []; }
		$submissions = self::rowsById(SubmissionTable::class, array_column($rows, 'SUBMISSION_ID'));
		$sources = self::rowsById(SourceTable::class, array_column($submissions, 'SOURCE_ID'));
		$versions = self::rowsById(VersionTable::class, array_column($rows, 'VERSION_ID'));
		$subjects = self::rowsById(SubjectTable::class, array_column($rows, 'SUBJECT_ID'));
		$roots = []; foreach ($subjects as $s) { $roots[] = (int)($s['CANONICAL_ID'] ?? $s['ID']); }
		$ids = array_map('intval', array_column($rows, 'ID'));
		$roots = array_unique($roots);
		$aliases = SubjectTable::query()->setSelect(['ID', 'CANONICAL_ID'])->where(
			(new ConditionTree())->logic('or')->whereIn('ID', $roots)->whereIn('CANONICAL_ID', $roots))->fetchAll();
		$rootByAlias = [];
		foreach ($aliases as $alias)
		{
			$rootByAlias[(int)$alias['ID']] = (int)($alias['CANONICAL_ID'] ?? $alias['ID']);
		}
		$byFact = []; $byRoot = [];
		foreach (EventTable::query()->setSelect(['*'])->whereIn('FACT_ID', $ids)->fetchAll() as $event)
		{
			$byFact[$event['FACT_ID']][] = $event;
		}
		if ($rootByAlias !== [])
		{
			foreach (EventTable::query()->setSelect(['*'])->whereNull('FACT_ID')
				->whereIn('SUBJECT_ID', array_keys($rootByAlias))->fetchAll() as $event)
			{
				$byRoot[$rootByAlias[(int)$event['SUBJECT_ID']]][] = $event;
			}
		}
		$result = [];
		foreach ($rows as $row)
		{
			$submission = $submissions[$row['SUBMISSION_ID']]; $subject = $subjects[$row['SUBJECT_ID']];
			$root = (int)($subject['CANONICAL_ID'] ?? $subject['ID']);
			$audit = array_merge($byFact[$row['ID']] ?? [], $byRoot[$root] ?? []);
			usort($audit, static fn(array $a, array $b): int => (int)$a['ID'] <=> (int)$b['ID']);
			$result[] = SubmissionService::hydrateFact($row, $submission, ['source' => $sources[$submission['SOURCE_ID']],
				'version' => $versions[$row['VERSION_ID']], 'events' => $audit]);
		}

		return $result;
	}

	public static function rowsById(string $model, array $ids): array
	{
		if ($ids === []) { return []; }

		return array_column($model::getList(['filter' => ['@ID' => array_unique($ids)]])->fetchAll(), null, 'ID');
	}
}
