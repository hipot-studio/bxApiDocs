<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Embedding;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Rest\Internal\Exception\Embedding\EmbeddingHandlerAlreadyBoundException;
use Bitrix\Rest\PlacementTable;

final class EmbeddingConflictGuard
{
	public static function run(\Closure $write): AddResult|UpdateResult
	{
		try
		{
			return $write();
		}
		catch (DuplicateEntryException $exception)
		{
			if (!self::isPlacementHandlerConflict($exception))
			{
				throw $exception;
			}

			throw new EmbeddingHandlerAlreadyBoundException($exception);
		}
	}

	private static function isPlacementHandlerConflict(DuplicateEntryException $exception): bool
	{
		$tableName = preg_quote(PlacementTable::getTableName(), '~');
		$pattern =
			'~\A\s*(?:INSERT\s+INTO|UPDATE)\s+["`]?'
			. $tableName
			. '["`]?(?=\s|\()~i'
		;

		return preg_match($pattern, (string)$exception->getQuery()) === 1;
	}
}
