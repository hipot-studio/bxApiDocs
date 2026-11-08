<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class Fact
{
	public array $substitutions;
	public array $originalBindings;
	public array $events;
	public DateTimeImmutable $acceptedAt;
	public DateTimeImmutable $presentedAt;
	public ?DateTimeImmutable $expiresAt;
	public ?DateTimeImmutable $revokedAt;

	public function __construct(
		public int $id,
		public int $sourceId,
		public int $formId,
		public string $sourceName,
		public int $submissionId,
		public int $documentId,
		public int $versionId,
		public int $versionNumber,
		public string $languageId,
		public string $contentHash,
		public SubjectReference $originalSubject,
		array $originalBindings,
		public ?int $resultId,
		public ?int $activityId,
		DateTimeImmutable $presentedAt,
		DateTimeImmutable $acceptedAt,
		public string $effectiveText,
		public string $effectiveLabel,
		array $substitutions,
		public string $textHash,
		public ExpiryRule $expiryRule,
		public string $timezone,
		?DateTimeImmutable $expiresAt,
		array $events,
		public string $status,
		?DateTimeImmutable $revokedAt = null,
		public ?int $revokedBy = null,
	)
	{
		foreach ([$id, $sourceId, $formId, $submissionId, $documentId, $versionId, $versionNumber] as $value)
		{
			if ($value <= 0 || $value > 2147483647) { throw new InvalidArgumentException('Invalid fact identifier.'); }
		}
		foreach ([$resultId, $activityId, $revokedBy] as $value)
		{
			if ($value !== null && ($value <= 0 || $value > 2147483647))
			{
				throw new InvalidArgumentException('Invalid fact identifier.');
			}
		}
		if (!in_array($status, ['accepted', 'expired', 'revoked'], true)
			|| ($revokedAt === null) !== ($revokedBy === null)
			|| ($status === 'revoked') !== ($revokedAt !== null)
			|| !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
			|| preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1
			|| !hash_equals(hash('sha256', $effectiveText), $textHash))
		{
			throw new InvalidArgumentException('Invalid fact snapshot.');
		}
		$bindings = [];
		foreach ($originalBindings as $binding)
		{
			if (!$binding instanceof SubjectReference) { throw new InvalidArgumentException('Invalid fact binding.'); }
			$bindings[] = $binding;
		}
		$this->originalBindings = $bindings;
		$this->substitutions = (new ManifestItem($documentId, $versionId, 1, $effectiveText, $effectiveLabel,
			$substitutions, $textHash, 'accepted'))->substitutions;
		$this->events = self::detach($events);
		$utc = new DateTimeZone('UTC');
		$this->presentedAt = $presentedAt->setTimezone($utc);
		$this->acceptedAt = $acceptedAt->setTimezone($utc);
		$this->expiresAt = $expiresAt?->setTimezone($utc);
		$this->revokedAt = $revokedAt?->setTimezone($utc);
	}

	private static function detach(array $values, int $depth = 0): array
	{
		if ($depth > 32) { throw new InvalidArgumentException('Invalid fact value.'); }
		$result = [];
		foreach ($values as $key => $value)
		{
			if (is_array($value)) { $result[$key] = self::detach($value, $depth + 1); }
			elseif ($value instanceof DateTimeImmutable)
			{
				$result[$key] = $value->setTimezone(new DateTimeZone('UTC'));
			}
			elseif ($value === null || is_scalar($value) && (!is_float($value) || is_finite($value)))
			{
				$result[$key] = $value;
			}
			else { throw new InvalidArgumentException('Invalid fact value.'); }
		}

		return $result;
	}
}
