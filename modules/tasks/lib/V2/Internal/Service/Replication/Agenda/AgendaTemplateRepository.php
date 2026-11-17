<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Agenda;

use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\TemplateTable;

class AgendaTemplateRepository
{
	/**
	 * Everything the projection reads: recurrence parameters, the author (timezone of the
	 * preferred time) and the repetition counter. The TEXT columns of the template body
	 * (description, files, tags) never reach the plan, so they stay out of the slice.
	 */
	private const PLAN_FIELDS = [
		'ID',
		'TITLE',
		'REPLICATE',
		'REPLICATE_TYPE',
		'REPLICATE_PARAMS',
		'CREATED_BY',
		'TPARAM_REPLICATION_COUNT',
	];

	/**
	 * Only active rules have upcoming postings - a paused one keeps its history in the agenda
	 * and is filtered out by the query instead of being loaded and skipped in PHP.
	 *
	 * @param int[] $templateIds
	 * @return array<int, TemplateObject>
	 */
	public function getPlannableByIds(array $templateIds): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$collection = TemplateTable::query()
			->setSelect(self::PLAN_FIELDS)
			->whereIn('ID', $templateIds)
			->where('REPLICATE', true)
			->exec()
			->fetchCollection()
		;

		$result = [];
		foreach ($collection as $template)
		{
			$result[(int)$template->getId()] = $template;
		}

		return $result;
	}

	/**
	 * Titles of the whole slice - the history of a paused rule needs its name too.
	 *
	 * @param int[] $templateIds
	 * @return array<int, string>
	 */
	public function getNamesByIds(array $templateIds): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$rows = TemplateTable::query()
			->setSelect(['ID', 'TITLE'])
			->whereIn('ID', $templateIds)
			->exec()
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['ID']] = (string)$row['TITLE'];
		}

		return $result;
	}
}
