<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\DB\ArrayResult;
use Bitrix\Main\Text\Converter;

/**
 * Result of a UserSignatureTable read served from the unified signature model.
 *
 * Carries the part of the ORM result the legacy readers actually use: fetch(), fetchAll(),
 * fetchObject(), fetchCollection() and getCount() for 'count_total'. Rows are stored whole, the
 * 'select' projection is applied on fetch() only — so object waking always sees the primary key.
 *
 * @deprecated Lives exactly as long as the UserSignatureTable adapter does, see the note there.
 */
class UserSignatureAdapterResult extends ArrayResult
{
	/** @var string[]|null Field names asked for in 'select'; null means every field. */
	private ?array $select;

	/**
	 * @param array<array<string, mixed>> $rows Legacy-shaped rows (ID, USER_ID, SENDER, SIGNATURE).
	 * @param string[]|null $select
	 * @param int|null $totalCount Row count before limit and offset, for 'count_total'.
	 */
	public function __construct(array $rows, ?array $select = null, ?int $totalCount = null)
	{
		$rows = array_values($rows);

		parent::__construct($rows);

		$this->select = $select;
		$this->setCount($totalCount ?? count($rows));
	}

	/**
	 * @return array|false
	 */
	public function fetch(?Converter $converter = null)
	{
		$row = parent::fetch($converter);

		return is_array($row) ? $this->applySelect($row) : $row;
	}

	/**
	 * @return \Bitrix\Mail\Internals\Entity\UserSignature|null
	 */
	public function fetchObject()
	{
		// Deliberately not $this->fetch(): an object needs the whole row, not the projection.
		$row = parent::fetch();

		return is_array($row) ? UserSignatureTable::wakeUpObject($row) : null;
	}

	/**
	 * @return \Bitrix\Mail\Internals\EO_UserSignature_Collection
	 */
	public function fetchCollection()
	{
		$rows = [];
		while ($row = parent::fetch())
		{
			$rows[] = $row;
		}

		return UserSignatureTable::wakeUpCollection($rows);
	}

	private function applySelect(array $row): array
	{
		if ($this->select === null)
		{
			return $row;
		}

		return array_intersect_key($row, array_flip($this->select));
	}
}
