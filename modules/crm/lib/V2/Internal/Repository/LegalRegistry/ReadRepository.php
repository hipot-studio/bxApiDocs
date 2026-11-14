<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\LegalRegistry;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Crm\V2\Internal\Access\LegalRegistry\RegistryAccess;
use Bitrix\Crm\V2\Internal\Model\LegalRegistry\{
	DocumentTable, EventTable, FactTable, SourceTable, SubmissionTable, SubjectTable, UsageTable, VersionTable,
};
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\SubmissionService;
use Bitrix\Crm\V2\Internal\Service\LegalRegistry\FormSourceGateway;
use Bitrix\Crm\V2\Public\Entity\LegalRegistry\{
	CatalogChoice, CatalogDocument, CatalogListItem, CatalogMetadata, FormUsage, RegistryResult,
};
use Bitrix\Main\ORM\Fields\{ExpressionField, IntegerField};
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\{Condition, ConditionTree};
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Application;
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

	public function getDocuments(int $userId, GridParams $params, string $search = '', bool $includeTotalCount = true,
		bool $summaryOnly = false)
		: RegistryResult
	{
		try
		{
			if ($userId <= 0 || !RegistryAccess::forUser($userId)->canReadForm())
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			[$filter, $cursor, $direction, $limit] = self::grid($params,
				['ID', 'NAME', 'RESPONSIBLE_ID', 'TAGS']);
			$query = DocumentTable::query()->setSelect(['ID'])->where($filter);
			if (mb_strlen($search) > 255 || preg_match('//u', $search) !== 1)
			{
				return SubmissionService::error('INVALID_PAYLOAD');
			}
			$search = trim($search);
			if ($search !== '')
			{
				$literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
				$searchFilter = (new ConditionTree())->logic('or')
					->whereLike('NAME', '%' . $literal . '%')
					->where(self::metadataContains('tags', [$search], 20), 1)
				;
				if (preg_match('/^[1-9][0-9]{0,9}$/D', $search) === 1 && (int)$search <= 2147483647)
				{
					$searchFilter->where('ID', (int)$search)
						->where(self::metadataContains('responsibleId', (int)$search, 21), 1)
					;
				}
				$query->where($searchFilter);
			}
			$count = $includeTotalCount ? (int)(clone $query)->queryCountTotal() : null;
			if ($cursor !== null) { $query->where('ID', $direction === 'ASC' ? '>' : '<', $cursor); }
			$page = $query->setOrder(['ID' => $direction])->setLimit($limit + 1)->fetchAll();
			$more = count($page) > $limit; if ($more) { array_pop($page); }
			$rows = [];
			if ($page !== [])
			{
				$ids = array_map('intval', array_column($page, 'ID'));
				$byId = self::rowsById(DocumentTable::class, $ids);
				foreach ($ids as $id) { $rows[] = $byId[$id]; }
			}

			$result = new RegistryResult();
			if ($summaryOnly) { $result->setCatalogListItems($this->catalogListItems($rows)); }
			else { $result->setDocuments($this->documents($rows)); }

			return $result->setTotalCount($count)
				->setNextCursor($more ? (int)$page[array_key_last($page)]['ID'] : null);
		}
		catch (\InvalidArgumentException|\Bitrix\Main\ArgumentException)
		{
			return SubmissionService::error('INVALID_PAYLOAD');
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	/** Reads one bounded page of exact version metadata under the catalog and form read scope. */
	public function getVersionChoices(
		int $userId,
		int $formId,
		?int $cursor,
		int $limit,
		string $search,
	): RegistryResult
	{
		try
		{
			if ($userId <= 0 || !RegistryAccess::forUser($userId)->canReadForm()
				|| $formId <= 0 || $formId > 2147483647)
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			if ($limit < 1 || $limit > 100 || ($cursor !== null && ($cursor <= 0 || $cursor > 2147483647))
				|| mb_strlen($search) > 255 || preg_match('//u', $search) !== 1)
			{
				return SubmissionService::error('INVALID_PAYLOAD');
			}
			if (!FormTable::query()->setSelect(['ID'])->where('ID', $formId)->setLimit(1)->fetch())
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			$query = VersionTable::query()
				->registerRuntimeField(new Reference('DOCUMENT', DocumentTable::class,
					Join::on('this.DOCUMENT_ID', 'ref.ID'), ['join_type' => 'INNER']))
				->setSelect(['ID', 'DOCUMENT_ID', 'NUMBER', 'STATE', 'VALID_FROM', 'VALID_TO',
					'DOCUMENT_NAME' => 'DOCUMENT.NAME', 'DOCUMENT_ARCHIVED' => 'DOCUMENT.ARCHIVED'])
				->setOrder(['ID' => 'ASC'])->setLimit($limit + 1);
			if ($cursor !== null) { $query->where('ID', '>', $cursor); }
			$search = trim($search);
			if ($search !== '')
			{
				if (preg_match('/^[1-9][0-9]{0,9}$/D', $search) === 1 && (int)$search <= 2147483647)
				{
					$query->where('DOCUMENT_ID', (int)$search);
				}
				else
				{
					$literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
					$query->whereLike('DOCUMENT.NAME', '%' . $literal . '%');
				}
			}
			$rows = $query->fetchAll();
			$more = count($rows) > $limit;
			if ($more) { array_pop($rows); }
			$choices = [];
			$now = time();
			foreach ($rows as $row)
			{
				$choices[] = new CatalogChoice(
					(int)$row['DOCUMENT_ID'], (int)$row['ID'], (int)$row['NUMBER'], $row['DOCUMENT_NAME'],
					$row['STATE'], $row['DOCUMENT_ARCHIVED'] === 'Y',
					$row['VALID_FROM'] === null ? null : SubmissionService::immutableDate($row['VALID_FROM']),
					$row['VALID_TO'] === null ? null : SubmissionService::immutableDate($row['VALID_TO']), $now,
				);
			}

			return (new RegistryResult())->setChoices($choices)
				->setNextCursor($more ? (int)$rows[array_key_last($rows)]['ID'] : null);
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
				'CASE WHEN EXISTS(SELECT 1 FROM b_crm_legal_usage u WHERE u.SOURCE_ID=%s'
				. ' AND u.DOCUMENT_ID=' . $documentId . ') THEN 1 ELSE 0 END', ['ID']);
			$exists->configureValueType(IntegerField::class);
			$query = SourceTable::query()->registerRuntimeField($exists)->setSelect(['*'])->where('LEGAL_USAGE_EXISTS', 1);
			$count = (int)(clone $query)->queryCountTotal();
			if ($cursor !== null) { $query->where('ID', '>', $cursor); }
			$rows = $query->setOrder(['ID' => 'ASC'])->setLimit($limit + 1)->fetchAll();
			$more = count($rows) > $limit; if ($more) { array_pop($rows); }
			$history = []; $result = [];
			if ($rows !== [])
			{
				$usages = UsageTable::query()
					->registerRuntimeField(new Reference('HISTORY_VERSION', VersionTable::class,
						Join::on('this.VERSION_ID', 'ref.ID')->whereColumn('this.DOCUMENT_ID', 'ref.DOCUMENT_ID'),
						['join_type' => 'INNER']))
					->setSelect(['ID', 'SOURCE_ID', 'VERSION_ID', 'REVISION', 'CHECKED', 'REQUIRED',
						'DATE_START', 'DATE_END', 'FORM_NAME_SNAPSHOT', 'VERSION_NUMBER' => 'HISTORY_VERSION.NUMBER'])
					->whereIn('SOURCE_ID', array_column($rows, 'ID'))->where('DOCUMENT_ID', $documentId)
					->setOrder(['ID' => 'ASC'])->fetchAll();
				foreach ($usages as $usage) { $history[$usage['SOURCE_ID']][] = $usage; }
				$current = FormSourceGateway::getInstance()->readCurrentForms(array_map('intval', array_column($rows, 'FORM_ID')));
				foreach ($rows as $source)
				{
					$versions = []; $intervals = []; $versionNumbers = [];
					foreach ($history[$source['ID']] as $usage)
					{
						$version = (int)$usage['VERSION_ID']; $versions[$version] = $version;
						$versionNumbers[$version] = (int)$usage['VERSION_NUMBER'];
						$intervals[] = ['versionId' => $version, 'revision' => (int)$usage['REVISION'],
							'checked' => $usage['CHECKED'] === 'Y', 'required' => $usage['REQUIRED'] === 'Y',
							'startsAt' => SubmissionService::immutableDate($usage['DATE_START']),
							'endsAt' => $usage['DATE_END'] === null ? null : SubmissionService::immutableDate($usage['DATE_END']),
							'formNameSnapshot' => $usage['FORM_NAME_SNAPSHOT']];
						}
						$form = $current[$source['FORM_ID']];
						$deleted = !$form['exists'] || $source['STATE'] !== 'active';
						$result[] = new FormUsage((int)$source['ID'], (int)$source['FORM_ID'], $documentId,
							$deleted ? $source['NAME_SNAPSHOT'] : $form['name'], $deleted,
							$deleted ? null : $form['url'],
							$source['STATE'], (int)$source['REVISION'], array_values($versions), $intervals, $versionNumbers);
				}
			}

			return (new RegistryResult())->setUsage($result)->setTotalCount($count)
				->setNextCursor($more ? (int)$rows[array_key_last($rows)]['ID'] : null)
			;
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	public function getUsageByForm(int $userId, int $formId): RegistryResult
	{
		try
		{
			if ($userId <= 0 || !RegistryAccess::forUser($userId)->canReadForm()
				|| $formId <= 0 || $formId > 2147483647)
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			$form = FormSourceGateway::getInstance()->readCurrentForms([$formId])[$formId];
			if (!$form['exists']) { return SubmissionService::error('NOT_FOUND_OR_DENIED'); }
			$source = SourceTable::query()->setSelect(['*'])->where('FORM_ID', $formId)->fetch();
			if (!$source) { return (new RegistryResult())->setUsage([])->setTotalCount(0); }
			$revision = (int)$source['REVISION'];
			if ($source['STATE'] !== 'active')
			{
				return (new RegistryResult())->setUsage([])->setTotalCount(0)->setRevision($revision);
			}
			$rows = UsageTable::query()->setSelect(['*'])->where('SOURCE_ID', (int)$source['ID'])
				->whereNull('DATE_END')->setOrder(['DOCUMENT_ID' => 'ASC'])->fetchAll();
			$usage = [];
			foreach ($rows as $row)
			{
				$versionId = (int)$row['VERSION_ID'];
				$interval = ['versionId' => $versionId, 'revision' => (int)$row['REVISION'],
					'checked' => $row['CHECKED'] === 'Y', 'required' => $row['REQUIRED'] === 'Y',
					'startsAt' => SubmissionService::immutableDate($row['DATE_START']), 'endsAt' => null,
					'formNameSnapshot' => $row['FORM_NAME_SNAPSHOT']];
				$usage[] = new FormUsage((int)$source['ID'], $formId, (int)$row['DOCUMENT_ID'],
					$form['name'], false, $form['url'], $source['STATE'], $revision, [$versionId], [$interval]);
			}

			return (new RegistryResult())->setUsage($usage)->setTotalCount(count($usage))->setRevision($revision);
		}
		catch (\Throwable) { return SubmissionService::error('STORAGE_ERROR'); }
	}

	public function getFormCounts(int $userId, array $documentIds): RegistryResult
	{
		try
		{
			if ($userId <= 0 || !RegistryAccess::forUser($userId)->canReadForm())
			{
				return SubmissionService::error('NOT_FOUND_OR_DENIED');
			}
			if (!array_is_list($documentIds) || count($documentIds) > 1000)
			{
				return SubmissionService::error('INVALID_PAYLOAD');
			}
			$ids = [];
			foreach ($documentIds as $id)
			{
				if (!is_int($id) || $id <= 0 || $id > 2147483647)
				{
					return SubmissionService::error('INVALID_PAYLOAD');
				}
				$ids[$id] = $id;
			}
			$counts = array_fill_keys(array_values($ids), 0);
			if ($ids === []) { return (new RegistryResult())->setFormCounts($counts); }
			$connection = Application::getConnection();
			foreach (array_chunk(array_values($ids), 300) as $chunk)
			{
				$sql = 'SELECT u.DOCUMENT_ID AS document_id, COUNT(DISTINCT s.FORM_ID) AS form_count'
					. ' FROM ' . UsageTable::getTableName() . ' u'
					. ' INNER JOIN ' . SourceTable::getTableName() . ' s ON s.ID = u.SOURCE_ID'
					. ' INNER JOIN ' . FormTable::getTableName() . ' f ON f.ID = s.FORM_ID'
					. " WHERE s.STATE = 'active' AND u.DOCUMENT_ID IN (" . implode(',', $chunk) . ')'
					. ' GROUP BY u.DOCUMENT_ID';
				foreach ($connection->query($sql)->fetchAll() as $row)
				{
				$row = array_change_key_case($row, CASE_UPPER);
				$counts[(int)$row['DOCUMENT_ID']] = (int)$row['FORM_COUNT'];
				}
			}

			return (new RegistryResult())->setFormCounts($counts);
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
				SubmissionService::immutableDate($row['CREATED_AT']), (int)$row['CREATED_BY'],
				$grouped[$row['ID']] ?? [], self::documentMetadata($row['METADATA']), (int)$row['REVISION']);
		}

		return $result;
	}

	/** Returns one row per document without loading version content or the full history. */
	private function catalogListItems(array $rows): array
	{
		if ($rows === []) { return []; }
		$ids = implode(',', array_map('intval', array_column($rows, 'ID')));
		$versions = Application::getConnection()->query(
			'SELECT summary.DOCUMENT_ID, latest.ID AS LATEST_ID, latest.NUMBER AS LATEST_NUMBER, '
			. 'latest.STATE AS LATEST_STATE, published.ID AS CURRENT_ID '
			. 'FROM (SELECT DOCUMENT_ID, MAX(NUMBER) AS LATEST_NUMBER, '
			. "MAX(CASE WHEN STATE = 'published' THEN NUMBER ELSE NULL END) AS CURRENT_NUMBER "
			. 'FROM b_crm_legal_version WHERE DOCUMENT_ID IN (' . $ids . ') GROUP BY DOCUMENT_ID) summary '
			. 'INNER JOIN b_crm_legal_version latest ON latest.DOCUMENT_ID = summary.DOCUMENT_ID '
			. 'AND latest.NUMBER = summary.LATEST_NUMBER '
			. 'LEFT JOIN b_crm_legal_version published ON published.DOCUMENT_ID = summary.DOCUMENT_ID '
			. 'AND published.NUMBER = summary.CURRENT_NUMBER'
		)->fetchAll();
		$byDocument = [];
		foreach ($versions as $version)
		{
			$version = array_change_key_case($version, CASE_UPPER);
			$byDocument[(int)$version['DOCUMENT_ID']] = $version;
		}
		$result = [];
		foreach ($rows as $row)
		{
			$version = $byDocument[(int)$row['ID']] ?? null;
			$metadata = self::documentMetadata($row['METADATA']);
			$result[] = new CatalogListItem((int)$row['ID'], $row['NAME'],
				$row['ARCHIVED'] === 'Y' ? 'archived' : ($version['LATEST_STATE'] ?? 'draft'),
				$version === null ? null : (int)$version['LATEST_ID'],
				$version === null ? null : (int)$version['LATEST_NUMBER'],
				$version === null || $version['CURRENT_ID'] === null ? null : (int)$version['CURRENT_ID'],
				$metadata?->responsibleId, $metadata?->tags ?? []);
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
				if (!$condition instanceof Condition || !in_array($condition->getColumn(), $allowed, true))
				{
					throw new \InvalidArgumentException('INVALID_PAYLOAD');
				}
				$field = $condition->getColumn(); $op = $condition->getOperator(); $value = $condition->getValue();
				if ($field === 'NAME' || $field === 'TAGS')
				{
					if ($op !== '=' || !is_string($value) || trim($value) === ''
						|| mb_strlen($value) > ($field === 'NAME' ? 255 : 4096)
						|| preg_match('//u', $value) !== 1)
					{
						throw new \InvalidArgumentException('INVALID_PAYLOAD');
					}
					if ($field === 'NAME')
					{
						$literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
						$filter->whereLike('NAME', '%' . $literal . '%');
					}
					else
					{
						$filter->where(self::metadataContains('tags', [$value], count($filter->getConditions())), 1);
					}
					continue;
				}
				if (!in_array($op, ['=', '>', '<'], true)
					|| !is_int($value) || $value <= 0 || $value > 2147483647)
				{
					throw new \InvalidArgumentException('INVALID_PAYLOAD');
				}
				if ($field === 'RESPONSIBLE_ID')
				{
					if ($op !== '=') { throw new \InvalidArgumentException('INVALID_PAYLOAD'); }
					$filter->where(self::metadataContains('responsibleId', $value,
						count($filter->getConditions())), 1);
					continue;
				}
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

	private static function metadataContains(string $key, int|array $value, int $index): ExpressionField
	{
		$json = json_encode([$key => $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		$connection = Application::getConnection();
		$literal = str_replace('%', '%%', $connection->getSqlHelper()->forSql($json));
		$mysqlPattern = $key === 'tags'
			? '(^|[,{])"tags":\\[("([^"\\\\]|\\\\.)*",)*'
				. preg_quote(json_encode($value[0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) . '(,|])'
			: '(^|[,{])"responsibleId":' . $value . '[,}]';
		$mysqlLiteral = str_replace('%', '%%', $connection->getSqlHelper()->forSql($mysqlPattern));
		$expression = match ($connection->getType())
		{
			'mysql' => "CASE WHEN %s REGEXP '{$mysqlLiteral}' THEN 1 ELSE 0 END",
			'pgsql' => "CASE WHEN (%s)::jsonb @> '{$literal}'::jsonb THEN 1 ELSE 0 END",
			default => throw new \RuntimeException('Unsupported registry search database.'),
		};

		return (new ExpressionField('LEGAL_CATALOG_METADATA_' . $index, $expression, ['METADATA']))
			->configureValueType(IntegerField::class);
	}

	private static function documentMetadata(?string $json): ?CatalogMetadata
	{
		if ($json === null) { return null; }
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		return new CatalogMetadata($data['legalEntityId'] ?? null, $data['purpose'] ?? null,
			$data['tags'] ?? [], $data['responsibleId'] ?? null);
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
