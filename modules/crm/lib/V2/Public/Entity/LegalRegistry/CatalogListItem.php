<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

/** The fields needed to render one row of the document catalog. */
final readonly class CatalogListItem
{
	/** @param list<string> $tags */
	public function __construct(
		public int $documentId,
		public string $name,
		public string $status,
		public ?int $versionId,
		public ?int $versionNumber,
		public ?int $currentVersionId,
		public ?int $responsibleId,
		public array $tags,
	) {}
}
