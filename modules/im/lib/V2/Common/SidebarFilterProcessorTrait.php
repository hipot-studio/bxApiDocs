<?php

namespace Bitrix\Im\V2\Common;

use Bitrix\Main\ORM\Query\Query;

trait SidebarFilterProcessorTrait
{
	protected static function processSidebarFilters(Query $query, array $filter, array $order, $fieldsMap = []): void
	{
		if (isset($filter['USER_ID']))
		{
			$query->whereIn($fieldsMap['AUTHOR_ID'] ?? 'AUTHOR_ID', $filter['USER_ID']);
		}

		$dateField = $fieldsMap['DATE_CREATE'] ?? 'DATE_CREATE';

		// the composite cursor continues the date order only: it says nothing about any other order
		if (isset($filter['LAST_DATE'], $filter['LAST_ID'], $order[$dateField]))
		{
			// the date is not unique, so the id resolves the ties in the same direction
			$idField = $fieldsMap['ID'] ?? 'ID';
			$operator = $order[$dateField] === 'DESC' ? '<' : '>';

			$query->where(
				Query::filter()
					->logic('or')
					->where($dateField, $operator, $filter['LAST_DATE'])
					->where(
						Query::filter()
							->where($dateField, $filter['LAST_DATE'])
							->where($idField, $operator, $filter['LAST_ID'])
					)
			);
		}
		elseif (isset($filter['LAST_ID']))
		{
			$operator = $order[$fieldsMap['ID'] ?? 'ID'] === 'DESC' ? '<' : '>';
			$query->where($fieldsMap['ID'] ?? 'ID', $operator, $filter['LAST_ID']);
		}

		if (isset($filter['CHAT_ID']))
		{
			$query->where($fieldsMap['CHAT_ID'] ?? 'CHAT_ID', $filter['CHAT_ID']);
		}

		if (isset($filter['DATE_FROM']))
		{
			$query->where($fieldsMap['DATE_CREATE'] ?? 'DATE_CREATE', '>=', $filter['DATE_FROM']);
		}

		if (isset($filter['DATE_TO']))
		{
			$query->where($fieldsMap['DATE_CREATE'] ?? 'DATE_CREATE', '<=', $filter['DATE_TO']);
		}

		if (isset($filter['START_ID']) && (int)$filter['START_ID'] > 0)
		{
			$query->where($fieldsMap['MESSAGE_ID'] ?? 'MESSAGE_ID', '>=', $filter['START_ID']);
		}

		if (isset($filter['END_ID']) && (int)$filter['END_ID'] > 0)
		{
			$query->where($fieldsMap['MESSAGE_ID'] ?? 'MESSAGE_ID', '<=', $filter['END_ID']);
		}
	}
}
