<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Dto;

use Bitrix\BIConnector\Internal\Digest\DigestPeriod;

/**
 * Values a report screen brings into the digest setup wizard beside the report itself. Each of them is
 * already checked against the report and the user; an absent value leaves its field empty.
 */
final class DigestSetupPreset
{
	public const KEY_PERIOD = 'period';
	public const KEY_SCOPE_VALUES = 'scopeValues';

	/**
	 * @param string|null $period Stored form of the digest period,
	 *     see {@see \Bitrix\BIConnector\Internal\Digest\DigestPeriodSelection}.
	 * @param array<string, int> $scopeValues Scope parameter code => value id, positive.
	 */
	public function __construct(
		public readonly ?string $period = null,
		public readonly array $scopeValues = [],
	)
	{
	}

	public function withDefaultPeriod(DigestPeriod $period): self
	{
		if ($this->period !== null)
		{
			return $this;
		}

		return new self($period->value, $this->scopeValues);
	}

	/**
	 * @return array{period?: string, scopeValues?: array<string, int>} Only the keys that carry a value.
	 */
	public function toPresetValues(): array
	{
		$values = [];
		if ($this->period !== null)
		{
			$values[self::KEY_PERIOD] = $this->period;
		}

		if ($this->scopeValues !== [])
		{
			$values[self::KEY_SCOPE_VALUES] = $this->scopeValues;
		}

		return $values;
	}
}
