<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Provider;

use Bitrix\Disk\Internal\Model\FavoriteObjectTable;
use Bitrix\Disk\Internal\Repository\FavoriteMarkRepository;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * Personal favorite marks for the folder list: the state of the rows of a page and the condition of the filter.
 */
final class FavoriteProvider
{
	private const MARK_FIELD = 'FAVORITE_MARK_OF_USER';

	public function __construct(
		private readonly FavoriteMarkRepository $repository,
	)
	{
	}

	/**
	 * @param int[] $objectIds ids of the rows of a page
	 * @return int[] the given ids marked by the user, in the given order
	 */
	public function getMarkedIds(int $userId, array $objectIds): array
	{
		return $this->repository->filterMarked($userId, $objectIds);
	}

	/**
	 * Narrows getList parameters of ObjectTable to the objects marked (or not marked) by the user. Rights, trashcan
	 * and sorting stay with the caller.
	 *
	 * @param array $parameters getList parameters of {@see \Bitrix\Disk\Internals\ObjectTable}
	 */
	public function applyListFilter(array $parameters, int $userId, bool $isFavorite): array
	{
		$parameters['runtime'][] = new ExpressionField(
			self::MARK_FIELD,
			'CASE WHEN EXISTS ('
				. 'SELECT 1 FROM ' . FavoriteObjectTable::getTableName() . ' fav'
				. ' WHERE fav.USER_ID = ' . $userId . ' AND fav.OBJECT_ID = %s'
			. ') THEN 1 ELSE 0 END',
			['ID'],
			['data_type' => 'integer'],
		);
		$parameters['filter']['=' . self::MARK_FIELD] = $isFavorite ? 1 : 0;

		return $parameters;
	}
}
