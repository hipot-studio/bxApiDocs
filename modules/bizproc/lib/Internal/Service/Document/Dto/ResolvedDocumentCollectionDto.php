<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Document\Dto;

use Bitrix\Bizproc\Internal\Entity\Document\DocumentComplexType;

readonly class ResolvedDocumentCollectionDto
{
	/**
	 * @var ResolvedDocumentDto[]
	 */
	public array $documents;

	/**
	 * @param ResolvedDocumentDto[] $documents
	 */
	public function __construct(array $documents)
	{
		foreach ($documents as $document)
		{
			if (!$document instanceof ResolvedDocumentDto)
			{
				throw new \TypeError('documents must contain only ' . ResolvedDocumentDto::class);
			}
		}

		$this->documents = $documents;
	}

	public function findByDocumentType(
		DocumentComplexType $complexDocumentType,
		bool $requireDocumentId = false,
	): ?ResolvedDocumentDto
	{
		foreach ($this->documents as $document)
		{
			if (
				(!$requireDocumentId || $document->complexDocumentId !== null)
				&& $document->complexDocumentType->equals($complexDocumentType)
			)
			{
				return $document;
			}
		}

		return null;
	}

	public function isEmpty(): bool
	{
		return $this->documents === [];
	}
}
