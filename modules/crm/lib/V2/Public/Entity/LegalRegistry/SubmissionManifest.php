<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SubmissionManifest
{
	/** @var list<ManifestItem> */
	public array $items;

	/**
	 * Captures one presentation with at most one answer per document.
	 *
	 * @param string $submissionKey
	 * @param int $formId
	 * @param int $sourceRevision
	 * @param list<ManifestItem> $items
	 * @param DateTimeImmutable $presentedAt
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		public string $submissionKey,
		public int $formId,
		public int $sourceRevision,
		array $items,
		public DateTimeImmutable $presentedAt,
	)
	{
		if (
			trim($submissionKey) === ''
			|| $formId <= 0 || $formId > 2147483647 || $sourceRevision <= 0 || $sourceRevision > 2147483647
			|| !array_is_list($items)
		)
		{
			throw new InvalidArgumentException('Invalid submission manifest.');
		}
		$snapshot = [];
		$documentIds = [];
		foreach ($items as $item)
		{
			if (!($item instanceof ManifestItem) || isset($documentIds[$item->documentId]))
			{
				throw new InvalidArgumentException('Invalid manifest documents.');
			}
			$documentIds[$item->documentId] = true;
			$snapshot[] = $item;
		}
		$this->items = $snapshot;
	}
}
