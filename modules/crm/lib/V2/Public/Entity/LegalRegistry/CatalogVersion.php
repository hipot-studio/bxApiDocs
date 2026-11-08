<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CatalogVersion
{
	/** @var array<string, string> */
	public array $substitutionSchema;

	/**
	 * Captures version content and catalog validity without deriving fact expiry.
	 *
	 * @param ?int $id
	 * @param ?int $documentId
	 * @param ?int $number
	 * @param ?int $revision
	 * @param string $state
	 * @param string $languageId
	 * @param string $template
	 * @param string $label
	 * @param array<string, string> $substitutionSchema
	 * @param string $contentHash
	 * @param ExpiryRule $expiryRule
	 * @param ?DateTimeImmutable $validFrom
	 * @param ?DateTimeImmutable $validTo
	 * @param ?string $displayUrl
	 * @param ?DateTimeImmutable $publishedAt
	 * @param ?int $publishedBy
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		public ?int $id,
		public ?int $documentId,
		public ?int $number,
		public ?int $revision,
		public string $state,
		public string $languageId,
		public string $template,
		public string $label,
		array $substitutionSchema,
		public string $contentHash,
		public ExpiryRule $expiryRule,
		public ?DateTimeImmutable $validFrom = null,
		public ?DateTimeImmutable $validTo = null,
		public ?string $displayUrl = null,
		public ?DateTimeImmutable $publishedAt = null,
		public ?int $publishedBy = null,
	)
	{
		foreach ([$id, $documentId, $number, $revision, $publishedBy] as $value)
		{
			if ($value !== null && ($value <= 0 || $value > 2147483647))
			{
				throw new InvalidArgumentException('Invalid catalog version identity.');
			}
		}
		if (
			!in_array($state, ['draft', 'published', 'archived'], true)
			|| trim($languageId) === ''
			|| preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1
			|| ($validFrom !== null && $validTo !== null && $validFrom > $validTo)
			|| (($publishedAt === null) !== ($publishedBy === null))
		)
		{
			throw new InvalidArgumentException('Invalid catalog version content.');
		}
		$snapshot = [];
		foreach ($substitutionSchema as $key => $type)
		{
			if (
				!is_string($key) || $key === ''
				|| !in_array($type, ['string', 'integer', 'number', 'boolean', 'string[]'], true)
			)
			{
				throw new InvalidArgumentException('Invalid substitution schema.');
			}
			$snapshot[$key] = $type;
		}
		$this->substitutionSchema = $snapshot;
	}
}
