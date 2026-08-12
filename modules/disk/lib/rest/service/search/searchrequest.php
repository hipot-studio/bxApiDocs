<?php

declare(strict_types=1);

namespace Bitrix\Disk\Rest\Service\Search;

use Bitrix\Main\Result;

final class SearchRequest
{
	private const MIN_QUERY_LENGTH = 3;
	private const MAX_QUERY_LENGTH = 255;
	private const MAX_OFFSET = 1000;

	private const FILTER_FIELDS = [
		'STORAGE_ID',
		'FOLDER_ID',
	];

	private function __construct(
		private readonly string $query,
		private readonly SearchType $type,
		private readonly ?int $storageId,
		private readonly ?int $folderId,
		private readonly int $offset,
	)
	{
	}

	public static function create(mixed $query, mixed $type, mixed $filter, int $offset = 0): Result
	{
		$result = new Result();

		$query = self::normalizeQuery($query);
		if ($query === null)
		{
			return $result->addError(SearchError::invalidQuery());
		}

		$type = self::normalizeType($type);
		if ($type === null)
		{
			return $result->addError(SearchError::invalidType());
		}

		if ($filter === null)
		{
			$filter = [];
		}
		elseif (!is_array($filter) || ($filter !== [] && array_is_list($filter)))
		{
			return $result->addError(SearchError::notFound());
		}

		if (array_diff(array_keys($filter), self::FILTER_FIELDS) !== [])
		{
			return $result->addError(SearchError::invalidFilter());
		}

		$storageId = self::getFilterId($filter, 'STORAGE_ID');
		if (array_key_exists('STORAGE_ID', $filter) && $storageId === null)
		{
			return $result->addError(SearchError::notFound());
		}

		$folderId = self::getFilterId($filter, 'FOLDER_ID');
		if (array_key_exists('FOLDER_ID', $filter) && $folderId === null)
		{
			return $result->addError(SearchError::notFound());
		}

		return $result->setData([
			'request' => new self(
				$query,
				$type,
				$storageId,
				$folderId,
				min(max(0, $offset), self::MAX_OFFSET),
			),
		]);
	}

	public function getQuery(): string
	{
		return $this->query;
	}

	public function getType(): SearchType
	{
		return $this->type;
	}

	public function getStorageId(): ?int
	{
		return $this->storageId;
	}

	public function getFolderId(): ?int
	{
		return $this->folderId;
	}

	public function getOffset(): int
	{
		return $this->offset;
	}

	public function getNextOffset(int $pageSize): ?int
	{
		$nextOffset = $this->offset + max(1, $pageSize);

		return $nextOffset <= self::MAX_OFFSET ? $nextOffset : null;
	}

	private static function normalizeQuery(mixed $query): ?string
	{
		if (!is_string($query))
		{
			return null;
		}

		$query = preg_replace('/[\s\p{Z}]+/u', ' ', $query);
		if ($query === null)
		{
			return null;
		}

		$query = trim($query);

		$queryLength = mb_strlen($query);

		return $queryLength >= self::MIN_QUERY_LENGTH && $queryLength <= self::MAX_QUERY_LENGTH
			? $query
			: null;
	}

	private static function normalizeType(mixed $type): ?SearchType
	{
		if ($type === null)
		{
			return SearchType::File;
		}

		return is_string($type) ? SearchType::tryFrom($type) : null;
	}

	private static function getFilterId(array $filter, string $field): ?int
	{
		if (!array_key_exists($field, $filter))
		{
			return null;
		}

		$value = $filter[$field];
		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}

		if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)
		{
			return null;
		}

		$maxValue = (string)PHP_INT_MAX;
		if (
			strlen($value) > strlen($maxValue)
			|| (strlen($value) === strlen($maxValue) && strcmp($value, $maxValue) > 0)
		)
		{
			return null;
		}

		return (int)$value;
	}
}
