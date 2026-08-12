<?php

declare(strict_types=1);

namespace Bitrix\Call\DTO\FollowUp;

/**
 * Mixin for V3 DTOs that strips top-level null entries from toArray() output —
 * while still respecting an opt-in whitelist of fields that must remain in the
 * payload even when their value is null.
 *
 * Two control knobs:
 *  - {@see keepAllNulls()} — disable null-filtering completely (used by `get`,
 *    where the API contract returns the full DTO shape).
 *  - {@see setExplicitFields()} / {@see addExplicitFields()} — list of property
 *    names to preserve even when null (used by `list`, mirroring the request's
 *    `select`: explicitly requested → must appear in response).
 *
 * Empty arrays (`[]`) are always kept — they convey a meaningful "no items" signal.
 */
trait NullCompactArrayTrait
{
	private bool $keepAllNulls = false;

	/** @var array<string, true> */
	private array $explicitFields = [];

	public function keepAllNulls(bool $value = true): self
	{
		$this->keepAllNulls = $value;

		return $this;
	}

	/**
	 * @param string[] $fields property names to keep in toArray() output even when their value is null
	 */
	public function setExplicitFields(array $fields): self
	{
		$this->explicitFields = array_fill_keys($fields, true);

		return $this;
	}

	/**
	 * @param string[] $fields
	 */
	public function addExplicitFields(array $fields): self
	{
		foreach ($fields as $field)
		{
			$this->explicitFields[$field] = true;
		}

		return $this;
	}

	public function toArray(bool $rawData = false): array
	{
		$values = parent::toArray($rawData);

		if ($this->keepAllNulls)
		{
			return $values;
		}

		return array_filter(
			$values,
			fn ($value, $key) => $value !== null || isset($this->explicitFields[$key]),
			ARRAY_FILTER_USE_BOTH,
		);
	}
}
