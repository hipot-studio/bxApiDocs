<?php

namespace Bitrix\Main\Composite\Internals;

use Bitrix\Main\Composite\Helper;
use Bitrix\Main\Composite\Internals\Model\PageTable;

/**
 * Accumulates the deleted pages and applies their removal to the size counter and to the records,
 * one query per batch, so that a break of the cleanup leaves the two in agreement
 */
class PageDeleteBatch
{
	public const MAX_KEYS = 500;

	/**
	 * CACHE_KEY is a varchar(2000), so the length of the delete statement is bounded by the keys, not by their count
	 */
	public const MAX_LENGTH = 262144;

	private array $cacheKeys = [];
	private int $length = 0;
	private float $bytes = 0.0;

	/**
	 * The last resort for a cleanup that ended without a flush: the files are already deleted,
	 * and nothing would ever free their bytes again. A shutdown by a fatal error skips the
	 * destructor, so it is an addition to the try/finally of the caller, not a replacement
	 */
	public function __destruct()
	{
		try
		{
			$this->flush();
		}
		catch (\Throwable $exception)
		{

		}
	}

	/**
	 * Adds the key of the deleted page and the bytes it freed, and flushes the batch when it is full
	 * @param string $cacheKey
	 * @param float $bytes the number of bytes whose removal is confirmed
	 * @return void
	 */
	public function add(string $cacheKey, float $bytes = 0.0): void
	{
		$this->cacheKeys[] = $cacheKey;
		$this->length += strlen($cacheKey);
		$this->bytes += $bytes;

		if (count($this->cacheKeys) >= self::MAX_KEYS || $this->length >= self::MAX_LENGTH)
		{
			$this->flush();
		}
	}

	/**
	 * Frees the bytes of the collected pages in the counter and removes their records.
	 * The counter goes first: the opposite order would inflate it forever if the cleanup breaks in between.
	 * The batch is emptied before either of them: a failed removal of the records must not leave
	 * the same bytes to be subtracted a second time by the next flush
	 * @return void
	 */
	public function flush(): void
	{
		if (empty($this->cacheKeys))
		{
			return;
		}

		$cacheKeys = $this->cacheKeys;
		$bytes = $this->bytes;

		$this->cacheKeys = [];
		$this->length = 0;
		$this->bytes = 0.0;

		Helper::updateCacheFileSize(-$bytes);
		PageTable::deleteByFilter(['@CACHE_KEY' => $cacheKeys]);
	}
}
