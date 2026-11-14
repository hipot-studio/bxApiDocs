<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CatalogDocument
{
	/** @var list<CatalogVersion> */
	public array $versions;

	/**
	 * Captures a document and its immutable versions.
	 *
	 * @param ?int $id
	 * @param string $name
	 * @param bool $archived
	 * @param ?string $originModule
	 * @param ?int $originId
	 * @param ?DateTimeImmutable $createdAt
	 * @param ?int $createdBy
	 * @param list<CatalogVersion> $versions
	 * @param ?CatalogMetadata $metadata
	 * @param ?int $revision
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		public ?int $id,
		public string $name,
		public bool $archived = false,
		public ?string $originModule = null,
		public ?int $originId = null,
		public ?DateTimeImmutable $createdAt = null,
		public ?int $createdBy = null,
		array $versions = [],
		public ?CatalogMetadata $metadata = null,
		public ?int $revision = null,
	)
	{
		foreach ([$id, $originId, $createdBy, $revision] as $value)
		{
			if ($value !== null && ($value <= 0 || $value > 2147483647))
			{
				throw new InvalidArgumentException('Invalid catalog document identity.');
			}
		}
		if (
			trim($name) === '' || (($originModule === null) !== ($originId === null))
			|| ($originModule !== null && trim($originModule) === '')
			|| (($createdAt === null) !== ($createdBy === null)) || !array_is_list($versions)
		)
		{
			throw new InvalidArgumentException('Invalid catalog document.');
		}
		$snapshot = [];
		$versionIds = [];
		foreach ($versions as $version)
		{
			if (
				!($version instanceof CatalogVersion)
				|| ($id !== null && $version->documentId !== null && $id !== $version->documentId)
				|| ($version->id !== null && isset($versionIds[$version->id]))
			)
			{
				throw new InvalidArgumentException('Invalid document versions.');
			}
			if ($version->id !== null)
			{
				$versionIds[$version->id] = true;
			}
			$snapshot[] = $version;
		}
		$this->versions = $snapshot;
	}

	/** A monotone, JavaScript-safe token for one document/version card. */
	public function cardRevision(?CatalogVersion $version = null): int
	{
		if ($this->id === null || $this->revision === null
			|| ($version !== null && ($version->documentId !== $this->id || $version->revision === null)))
		{
			throw new InvalidArgumentException('Invalid catalog card revision.');
		}

		if ($version === null)
		{
			return $this->revision + (int)$this->archived;
		}

		$state = match ($version->state)
		{
			'draft' => 0,
			'published' => 1,
			'archived' => 2,
		};

		return $this->revision + $version->revision + $state + (int)$this->archived - 1;
	}
}
