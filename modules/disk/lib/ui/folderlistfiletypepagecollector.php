<?php

namespace Bitrix\Disk\Ui;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\FilePicker\FileTypeClassifier;
use Bitrix\Disk\FilePicker\Filter;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Result;

/**
 * Turns the FILE_TYPE_FILTERS value of the folder list filter into a query prefilter and an exact page of files.
 * It is local to the folder list: the file picker component and its provider are not involved.
 * One request reads at most MAX_CANDIDATES_PER_REQUEST candidates, so a large folder with rare matches
 * cannot keep the worker and the database busy: the page ends early instead.
 */
final class FolderListFileTypePageCollector
{
	public const CANDIDATE_BATCH_SIZE = 100;
	public const MAX_CANDIDATES_PER_REQUEST = 2000;

	/** An unusable value never widens the query: it selects nothing instead. */
	private const EMPTY_RESULT_FILTER = [
		'=TYPE' => ObjectTable::TYPE_FILE,
		'=ID' => 0,
	];

	private function __construct(
		private readonly ?Filter $filter,
		private readonly FileTypeClassifier $classifier,
	)
	{
	}

	/**
	 * Builds the collector from the raw filter field value.
	 *
	 * @param mixed $rawValue Value of the FILE_TYPE_FILTERS field as the filter has saved it.
	 * @param FileTypeClassifier $classifier Classifier shared with the file picker.
	 * @return self
	 */
	public static function createFromFilterValue(
		mixed $rawValue,
		FileTypeClassifier $classifier = new FileTypeClassifier(),
	): self
	{
		if (!is_array($rawValue))
		{
			return new self(null, $classifier);
		}

		$filterResult = Filter::create(Filter::OBJECT_TYPE_FILES, [], $rawValue);
		if (!$filterResult->isSuccess())
		{
			return new self(null, $classifier);
		}

		return new self($filterResult->getData()['filter'], $classifier);
	}

	/**
	 * Tells whether the raw value describes a known set of categories.
	 *
	 * @return bool
	 */
	public function isValid(): bool
	{
		return $this->filter !== null;
	}

	/**
	 * Tells whether the folder list has to be limited to files of the selected categories.
	 *
	 * @return bool
	 */
	public function isActive(): bool
	{
		return $this->filter !== null && $this->filter->getFileTypeFilters() !== [];
	}

	/**
	 * Tells whether the stored file type alone cannot tell the selected categories apart.
	 *
	 * @return bool
	 */
	public function needsExactMatching(): bool
	{
		return $this->isActive() && $this->filter->needsRuntimeFileTypeFiltering();
	}

	/**
	 * Returns the ORM condition added to the query of the folder list before the page is built.
	 *
	 * @return array Empty when the filter is not active.
	 */
	public function getObjectQueryFilter(): array
	{
		if (!$this->isValid())
		{
			return self::EMPTY_RESULT_FILTER;
		}

		return $this->isActive() ? $this->filter->getObjectQueryFilter() : [];
	}

	/**
	 * Collects the page of exactly classified files, plus one extra object for the next page flag.
	 * The caller keeps the candidate order stable across batches. The page may come back short
	 * when the candidate budget of the request runs out before it is full.
	 *
	 * @param callable $loadCandidateBatch function(int $offset, int $limit): Result with data ['objects' => BaseObject[]].
	 * @param int $pageSize Requested page size.
	 * @param int $resultOffset Offset counted in matched objects, not in candidates.
	 * @return Result with data ['objects' => BaseObject[]]; errors of the loader are passed as they are.
	 */
	public function collectPage(callable $loadCandidateBatch, int $pageSize, int $resultOffset): Result
	{
		$result = new Result();
		$pageObjects = [];
		if (!$this->isActive())
		{
			return $result->setData(['objects' => $pageObjects]);
		}

		$pageLimit = max(0, $pageSize) + 1;
		$resultOffset = max(0, $resultOffset);
		$candidateOffset = 0;
		$matchedOffset = 0;
		$lastCandidateId = null;

		while (count($pageObjects) < $pageLimit && $candidateOffset < self::MAX_CANDIDATES_PER_REQUEST)
		{
			$batchResult = $loadCandidateBatch($candidateOffset, self::CANDIDATE_BATCH_SIZE);
			if (!$batchResult->isSuccess())
			{
				return $result->addErrors($batchResult->getErrors());
			}

			$batch = $batchResult->getData()['objects'] ?? [];
			if (empty($batch))
			{
				break;
			}

			foreach ($batch as $object)
			{
				$candidateId = $object instanceof BaseObject ? (int)$object->getId() : 0;
				if ($candidateId <= 0 || $candidateId === $lastCandidateId)
				{
					continue;
				}

				$lastCandidateId = $candidateId;
				if (!$this->filter->shouldReturnObject($object, $this->classifier))
				{
					continue;
				}

				if ($matchedOffset < $resultOffset)
				{
					$matchedOffset++;

					continue;
				}

				$pageObjects[] = $object;
				if (count($pageObjects) === $pageLimit)
				{
					break;
				}
			}

			$candidateOffset += count($batch);
			if (count($batch) < self::CANDIDATE_BATCH_SIZE)
			{
				break;
			}
		}

		return $result->setData(['objects' => $pageObjects]);
	}
}
