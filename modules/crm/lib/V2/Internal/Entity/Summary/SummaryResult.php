<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Summary;

/**
 * The shape of a summary of one CRM entity: what it is about and what its sections hold.
 *
 * The type describes the form only - it neither knows the tool that answers with it nor phrases any
 * text. Which sections a summary has is up to the service of the entity, so a summary of a deal
 * reuses the same shell with a list of its own.
 *
 * `toArray()` is the wire form of the summary: the keys are normative, so renaming one breaks whoever
 * reads them, and a consumer reads nothing else.
 *
 * @internal
 */
final class SummaryResult
{
	/**
	 * @param string $entityType Lowercase entity type name, `contact` or `company` for a client.
	 * @param array<string, SummarySection> $sections Keyed by the section code of the entity.
	 * @param bool $isSelectionCapped The narrow selection the summary is built from hit its ceiling,
	 * so the history behind it is wider than the one summed up.
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly int $entityId,
		private readonly array $sections,
		private readonly bool $isSelectionCapped = false,
	)
	{
	}

	public function isIncomplete(): bool
	{
		if ($this->isSelectionCapped)
		{
			return true;
		}

		foreach ($this->sections as $section)
		{
			if ($section->isTruncated())
			{
				return true;
			}
		}

		return false;
	}

	public function toArray(): array
	{
		$sections = [];
		foreach ($this->sections as $code => $section)
		{
			$sections[$code] = $section->toArray();
		}

		return [
			'entityType' => $this->entityType,
			'entityId' => $this->entityId,
			'isIncomplete' => $this->isIncomplete(),
			'sections' => $sections,
		];
	}
}
