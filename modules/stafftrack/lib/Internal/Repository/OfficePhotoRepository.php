<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Internal\Model\OfficePhoto;
use Bitrix\StaffTrack\Internal\Model\OfficePhotoTable;

/**
 * Storage of the photos of an office, row by row: a photo is added and removed on its own, and the set
 * is never rewritten as a whole. The two aggregates below are read beside an insert and are not atomic
 * with it - that is what makes the limit an inexact barrier and lets two inserts share a SORT.
 */
class OfficePhotoRepository
{
	/**
	 * @return OfficePhoto[]
	 */
	public function getListByOfficeId(int $officeId): array
	{
		return OfficePhotoTable::query()
			->setSelect(['*'])
			->where('OFFICE_ID', $officeId)
			// SORT leaves the order of a tie to the database; the identifier settles it.
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->fetchCollection()
			->getAll()
		;
	}

	/**
	 * The photos of several offices in one query: the directory is read as a whole, and asking office
	 * by office would cost a query per office.
	 *
	 * @param int[] $officeIds
	 * @return OfficePhoto[]
	 */
	public function getListByOfficeIds(array $officeIds): array
	{
		if ($officeIds === [])
		{
			return [];
		}

		return OfficePhotoTable::query()
			->setSelect(['*'])
			->whereIn('OFFICE_ID', $officeIds)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->fetchCollection()
			->getAll()
		;
	}

	public function add(int $officeId, int $fileId, int $sort, int $createdBy): AddResult
	{
		return OfficePhotoTable::add([
			'OFFICE_ID' => $officeId,
			'FILE_ID' => $fileId,
			'SORT' => $sort,
			'CREATED_BY' => $createdBy,
		]);
	}

	public function delete(int $id): DeleteResult
	{
		return OfficePhotoTable::delete($id);
	}

	/**
	 * The whole set of an office at once. A mass delete answers with nothing at all and reports a failure
	 * by raising, so the exception is carried back as an error: the caller removes these rows inside the
	 * transaction of the card and has to be able to take the card back with them.
	 *
	 * The message of the exception stays out of the error: a storage failure carries the query it failed
	 * on, and that query carries the coordinates of the office.
	 */
	public function deleteByOfficeId(int $officeId): Result
	{
		$result = new Result();

		try
		{
			OfficePhotoTable::deleteByFilter(['=OFFICE_ID' => $officeId]);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error(
				$exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine(),
			));
		}

		return $result;
	}

	public function countByOfficeId(int $officeId): int
	{
		$row = OfficePhotoTable::query()
			->addSelect(Query::expr()->count('ID'), 'CNT')
			->where('OFFICE_ID', $officeId)
			->exec()
			->fetch()
		;

		return (int)($row['CNT'] ?? 0);
	}

	/**
	 * The highest order number an office has given out. Zero for an office without photos, so the first
	 * one added gets one.
	 */
	public function getMaxSortByOfficeId(int $officeId): int
	{
		$row = OfficePhotoTable::query()
			->addSelect(Query::expr()->max('SORT'), 'MAX_SORT')
			->where('OFFICE_ID', $officeId)
			->exec()
			->fetch()
		;

		return (int)($row['MAX_SORT'] ?? 0);
	}
}
