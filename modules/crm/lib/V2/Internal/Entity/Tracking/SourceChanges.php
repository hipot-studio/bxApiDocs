<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * The values of a source the caller passed for an add or an update; null - not passed, kept as it is.
 *
 * The values are trimmed. The empty values of a list are dropped, so a list of blanks clears the field; the
 * string "0" is a value.
 *
 * @internal
 */
final readonly class SourceChanges
{
	public ?string $name;
	public ?string $color;
	/** @var list<string>|null */
	public ?array $refDomains;
	/** @var list<string>|null */
	public ?array $utmSources;

	/**
	 * @param list<string>|null $refDomains replace the stored domains of the source as a whole
	 * @param list<string>|null $utmSources replace the stored UTM sources of the source as a whole
	 */
	public function __construct(
		?string $name = null,
		?string $color = null,
		?array $refDomains = null,
		?array $utmSources = null,
	)
	{
		$this->name = $name === null ? null : trim($name);
		$this->color = $color === null ? null : trim($color);
		$this->refDomains = self::normalizeList($refDomains);
		$this->utmSources = self::normalizeList($utmSources);
	}

	/**
	 * Whether the values the visits of the source are recognized by are passed: of all the values of a source
	 * only they reach the call tracker of the sites.
	 */
	public function changesRecognition(): bool
	{
		return $this->refDomains !== null || $this->utmSources !== null;
	}

	/**
	 * @return list<string>|null
	 */
	private static function normalizeList(?array $values): ?array
	{
		if ($values === null)
		{
			return null;
		}

		$normalized = [];
		foreach ($values as $value)
		{
			$value = trim((string)$value);
			if ($value !== '')
			{
				$normalized[] = $value;
			}
		}

		return $normalized;
	}
}
