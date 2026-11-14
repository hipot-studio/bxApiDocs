<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use DateTimeImmutable;
use InvalidArgumentException;

/** Lightweight exact version metadata for the form editor. */
final readonly class CatalogChoice
{
	public string $status;
	public bool $eligible;

	/**
	 * Derives selection eligibility without loading version content.
	 *
	 * @param int $documentId
	 * @param int $versionId
	 * @param int $number
	 * @param string $name
	 * @param string $state
	 * @param bool $archived
	 * @param ?DateTimeImmutable $validFrom
	 * @param ?DateTimeImmutable $validTo
	 * @param ?int $now
	 */
	public function __construct(
		public int $documentId,
		public int $versionId,
		public int $number,
		public string $name,
		string $state,
		bool $archived,
		?DateTimeImmutable $validFrom = null,
		?DateTimeImmutable $validTo = null,
		?int $now = null,
	)
	{
		foreach ([$documentId, $versionId, $number] as $id)
		{
			if ($id <= 0 || $id > 2147483647) { throw new InvalidArgumentException('Invalid catalog choice.'); }
		}
		if (!in_array($state, ['draft', 'published', 'archived'], true) || trim($name) === '')
		{
			throw new InvalidArgumentException('Invalid catalog choice.');
		}
		$this->status = $archived ? 'archived' : $state;
		$now ??= time();
		$this->eligible = !$archived && $state === 'published'
			&& ($validFrom === null || $validFrom->getTimestamp() <= $now)
			&& ($validTo === null || $validTo->getTimestamp() >= $now);
	}
}
