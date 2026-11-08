<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Service;

use Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable;
use Bitrix\Bizproc\Public\ProcessData\Enum\SharedValueUsage;
use Bitrix\Bizproc\Public\Service\Template\ExpressionReferenceScanner;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Main\ORM\Query\Query;

/**
 * A shared value belongs to the portal, so the question "who uses it" reaches beyond the template on the
 * canvas: the other templates are read for references to it until the first one that holds one. A reference
 * living only in a draft of somebody else holds the value exactly as a published one does, and so does one
 * living in the snapshot a pilot version keeps running from, so the drafts and the snapshots are read the
 * same way. The templates are stored compressed, so no filter of the database narrows the reading —
 * every row read is unpacked and asked about the reference itself.
 *
 * The answer is exact while the budget of the reading holds: every stored row is read until a reference is
 * met. A budget spent answers `Unknown` and never `Free` — a value called free by a reading that stopped
 * halfway would be deleted out of the processes still holding it. The budget itself is what keeps a portal of
 * any size out of a request held open until it is killed; the exact answer of such a portal belongs to an
 * index of references written when a template is saved, not to a larger number here.
 */
final class SharedValueUsageService
{
	/** A reference lives in the scheme of a template and in the default value of any of its data blocks. */
	private const SEARCHED_FIELDS = ['TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	private const PAGE_SIZE = 50;

	/** Seconds of reading the portal answers within. Every row read is unpacked, so the bound is a time one. */
	private const SCAN_TIME_LIMIT = 3.0;

	/**
	 * @param int $exceptDraftId The draft the canvas was loaded from: everything else the portal keeps is
	 *     somebody else's. A draft that is not named is not excluded — a value is never freed by a guess.
	 */
	public function usageByOtherTemplates(
		string $sourceType,
		string $key,
		int $exceptTemplateId = 0,
		int $exceptDraftId = 0,
	): SharedValueUsage
	{
		return $this->usageInPortal($sourceType, $key, $exceptTemplateId, $exceptDraftId);
	}

	/**
	 * Everything the portal keeps, except the very draft the canvas was loaded from: the canvas itself is
	 * asked by the caller and is fresher than that draft. Another draft of the same template belongs to
	 * another editing session and holds the value as any other row does. The stored row of that very template
	 * is asked all the same — its published version answers from it until the canvas is published.
	 */
	public function usageOutsideCanvas(string $sourceType, string $key, int $canvasDraftId = 0): SharedValueUsage
	{
		return $this->usageInPortal($sourceType, $key, 0, $canvasDraftId);
	}

	/**
	 * What the versions of one template answer from: its stored row and the snapshot its pilot version keeps
	 * running from until the template is published again.
	 */
	public function isUsedByTemplate(string $sourceType, string $key, int $templateId): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		$row = WorkflowTemplateTable::getRow([
			'select' => self::SEARCHED_FIELDS,
			'filter' => ['=ID' => $templateId],
		]);

		foreach (self::SEARCHED_FIELDS as $field)
		{
			if ($this->hasReference($row[$field] ?? null, $sourceType, $key))
			{
				return true;
			}
		}

		$pilot = WorkflowTemplatePilotTable::getRow([
			'select' => ['TEMPLATE_DATA'],
			'filter' => ['=TEMPLATE_ID' => $templateId],
		]);

		return $this->hasReference($pilot['TEMPLATE_DATA'] ?? null, $sourceType, $key);
	}

	/** The readings share one budget: the caller waits for all of them as for one. */
	private function usageInPortal(
		string $sourceType,
		string $key,
		int $exceptTemplateId,
		int $exceptDraftId,
	): SharedValueUsage
	{
		$deadline = microtime(true) + self::SCAN_TIME_LIMIT;

		$readings = [
			[fn(): Query => $this->templatesQuery($exceptTemplateId), self::SEARCHED_FIELDS],
			[fn(): Query => $this->draftsQuery($exceptDraftId), ['TEMPLATE_DATA']],
			[fn(): Query => $this->pilotsQuery($exceptTemplateId), ['TEMPLATE_DATA']],
		];

		foreach ($readings as [$queryFactory, $fields])
		{
			$usage = $this->findReferenceInPages($queryFactory, $fields, $sourceType, $key, $deadline);

			if ($usage !== SharedValueUsage::Free)
			{
				return $usage;
			}
		}

		return SharedValueUsage::Free;
	}

	private function templatesQuery(int $exceptTemplateId): Query
	{
		$query = WorkflowTemplateTable::query()->setSelect(array_merge(['ID'], self::SEARCHED_FIELDS));

		if ($exceptTemplateId > 0)
		{
			$query->whereNot('ID', $exceptTemplateId);
		}

		return $query;
	}

	/** The whole configuration of a draft is one field, and the reference is looked for in all of it. */
	private function draftsQuery(int $exceptDraftId): Query
	{
		$query = WorkflowTemplateDraftTable::query()->setSelect(['ID', 'TEMPLATE_DATA']);

		if ($exceptDraftId > 0)
		{
			$query->whereNot('ID', $exceptDraftId);
		}

		return $query;
	}

	/**
	 * The whole snapshot of a pilot version is one field, and the reference is looked for in all of it. The
	 * snapshot of the template the canvas was loaded from is asked by {@see isUsedByTemplate()} instead: its
	 * pilot version runs on while the canvas is edited, so the value is kept and not refused.
	 */
	private function pilotsQuery(int $exceptTemplateId): Query
	{
		$query = WorkflowTemplatePilotTable::query()->setSelect(['ID', 'TEMPLATE_DATA']);

		if ($exceptTemplateId > 0)
		{
			$query->whereNot('TEMPLATE_ID', $exceptTemplateId);
		}

		return $query;
	}

	/**
	 * @param callable(): Query $queryFactory A fresh query per page: keyset paging adds its own bound.
	 * @param string[] $fields
	 */
	private function findReferenceInPages(
		callable $queryFactory,
		array $fields,
		string $sourceType,
		string $key,
		float $deadline,
	): SharedValueUsage
	{
		$lastId = 0;

		while (true)
		{
			$rows = $queryFactory()
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit(self::PAGE_SIZE)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$lastId = (int)$row['ID'];

				foreach ($fields as $field)
				{
					if ($this->hasReference($row[$field] ?? null, $sourceType, $key))
					{
						return SharedValueUsage::Used;
					}
				}
			}

			if (count($rows) < self::PAGE_SIZE)
			{
				return SharedValueUsage::Free;
			}

			if (microtime(true) >= $deadline)
			{
				return SharedValueUsage::Unknown;
			}
		}
	}

	private function hasReference(mixed $value, string $sourceType, string $key): bool
	{
		return ExpressionReferenceScanner::hasReference($value, [$sourceType], $key)
			|| $this->isWriteTarget($value, $sourceType, $key)
		;
	}

	/**
	 * A writer names its target by the key of a row of its own property and never by a reference in the text
	 * (Q-6), so the reading of the row as text answers nothing about it: `SetGlobalVariableActivity` writing
	 * into a shared value under its bare key would leave the value looking free. Every node the row carries is
	 * asked, wherever it stands - a stored template holds its nodes in the field, a draft in its configuration.
	 */
	private function isWriteTarget(mixed $value, string $sourceType, string $key): bool
	{
		if (!is_array($value))
		{
			return false;
		}

		foreach (ActivityWriteTargets::of($value) as $target)
		{
			if ($target['sourceType'] === $sourceType && mb_strtolower($target['key']) === mb_strtolower($key))
			{
				return true;
			}
		}

		foreach ($value as $item)
		{
			if ($this->isWriteTarget($item, $sourceType, $key))
			{
				return true;
			}
		}

		return false;
	}
}
