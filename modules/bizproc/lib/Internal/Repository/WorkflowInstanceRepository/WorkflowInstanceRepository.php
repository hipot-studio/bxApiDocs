<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\WorkflowInstanceRepository;

use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Type\DateTime;

/**
 * Reads running workflow instances page by page.
 *
 * What makes an instance interesting is the caller's knowledge: the filter arrives as a parameter, and the
 * repository has no notion of a publication, of a cut-off boundary or of any other semantics of the caller.
 *
 * A row of this entity exists only while its process is neither completed nor terminated, so a caller that
 * terminates what it read sees the selection shrink by itself and needs no cursor for that. What does need
 * one is a caller that leaves rows where they are: every page says where it ended, and handing that place
 * back reads on from there. The place is opaque to the caller - see {@see InstanceIdsPage}.
 */
class WorkflowInstanceRepository
{
	/** Between the moment and the id inside a token: neither part can hold it. */
	private const TOKEN_DELIMITER = ':';

	/**
	 * A page of ids of instances matching the filter of the caller, and the place the page ended at.
	 *
	 * The order is fixed on purpose: without it the composition of a page is left to the database, and a
	 * caller that walks pages until they run out cannot tell a page that moved from a page that did not.
	 *
	 * `(WORKFLOW_TEMPLATE_ID, STARTED)` is indexed, so a filter by one template reads the rows of a page in
	 * order already. The trailing `ID` is free of charge on InnoDB alone, where the primary key trails every
	 * secondary index; PostgreSQL has no such rule and sorts the rows sharing one STARTED before it answers.
	 * That group is what a single second of starts of one template holds, so the cost is bounded by how many
	 * processes of one template a portal starts within a second - and an index of `(WORKFLOW_TEMPLATE_ID,
	 * STARTED, ID)` is what removes it, at the price of one more index on a table of every portal. Decided
	 * against on 2026-09-03: the price is paid by every portal, the sort by every portal that runs on
	 * PostgreSQL and starts many processes of one template within a second.
	 *
	 * @param array $filter ORM filter of the caller
	 * @param int $limit size of the page, one row at the least
	 * @param string $afterToken the place the caller stopped at, exactly as a previous page answered it; an
	 *     empty string reads the selection from its beginning. The page carries its own end, so reading on
	 *     costs no query of its own. A place whose row is no longer there is not a problem to answer: the
	 *     comparison is by value, so the reading goes on from where that row used to be.
	 *
	 * @throws ArgumentOutOfRangeException on a limit that is not positive. The ORM builds a query with no
	 *     LIMIT at all for such a value, so a page would silently become the whole table; a caller that
	 *     computed its page size wrong is told so instead.
	 */
	public function getIdsPage(array $filter, int $limit, string $afterToken = ''): InstanceIdsPage
	{
		if ($limit <= 0)
		{
			throw new ArgumentOutOfRangeException('limit', 1);
		}

		$after = self::placeOfToken($afterToken);
		if ($after !== null)
		{
			// The place is compared as a pair, exactly as the order sorts it: instances of one template
			// sharing a second are told apart by the id alone, so reading on from the second alone would
			// skip whatever else that second holds - and what it holds has not been looked at.
			$filter = [
				'LOGIC' => 'AND',
				$filter,
				[
					'LOGIC' => 'OR',
					['>STARTED' => $after['STARTED']],
					['=STARTED' => $after['STARTED'], '>ID' => $after['ID']],
				],
			];
		}

		$rows = WorkflowInstanceTable::query()
			->setSelect(['ID', 'STARTED'])
			->setFilter($filter)
			->setOrder(['STARTED' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		$lastRow = $rows === [] ? null : $rows[array_key_last($rows)];
		$lastMoment = $lastRow === null ? null : ($lastRow['STARTED'] ?? null);

		return new InstanceIdsPage(
			array_column($rows, 'ID'),
			$lastRow === null ? '' : self::tokenOfRow($lastRow),
			$lastMoment instanceof DateTime ? $lastMoment->getTimestamp() : 0,
		);
	}

	/**
	 * The place of a row of a page, as a token to read on from.
	 *
	 * The moment travels as a whole number of seconds: a token has to survive being carried in the
	 * parameters of an agent, and those hold nothing but integers and strings
	 * ({@see \Bitrix\Main\Update\Stepper::makeArguments()}).
	 *
	 * @param array{ID?: mixed, STARTED?: mixed} $row
	 */
	private static function tokenOfRow(array $row): string
	{
		$started = $row['STARTED'] ?? null;
		$id = (string)($row['ID'] ?? '');

		if (!$started instanceof DateTime || $id === '')
		{
			return '';
		}

		return $started->getTimestamp() . self::TOKEN_DELIMITER . $id;
	}

	/**
	 * The pair the token stands for, or null when there is no place in it to read on from.
	 *
	 * A token that says nothing readable is answered the same way as an empty one - by reading the selection
	 * from its beginning. The alternative, refusing the call, would turn a token spoiled anywhere on its way
	 * into a job that stops nothing at all.
	 *
	 * @return array{STARTED: DateTime, ID: string}|null
	 */
	private static function placeOfToken(string $token): ?array
	{
		if ($token === '')
		{
			return null;
		}

		[$startedTimestamp, $id] = array_pad(explode(self::TOKEN_DELIMITER, $token, 2), 2, '');
		if ((int)$startedTimestamp <= 0 || $id === '')
		{
			return null;
		}

		return ['STARTED' => DateTime::createFromTimestamp((int)$startedTimestamp), 'ID' => $id];
	}
}
