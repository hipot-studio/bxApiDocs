<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use InvalidArgumentException;

final readonly class ManifestItem
{
	/** @var array<string, string|int|float|bool|null|list<string>> */
	public array $substitutions;

	/**
	 * Captures exact presentation values without granting trust to caller input.
	 *
	 * @param int $documentId
	 * @param int $versionId
	 * @param int $bindingRevision
	 * @param string $effectiveText
	 * @param string $effectiveLabel
	 * @param array<string, string|int|float|bool|null|list<string>> $substitutions
	 * @param string $textHash
	 * @param string $answer
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		public int $documentId,
		public int $versionId,
		public int $bindingRevision,
		public string $effectiveText,
		public string $effectiveLabel,
		array $substitutions,
		public string $textHash,
		public string $answer,
	)
	{
		foreach ([$documentId, $versionId, $bindingRevision] as $value)
		{
			if ($value <= 0 || $value > 2147483647)
			{
				throw new InvalidArgumentException('Invalid manifest item identity.');
			}
		}
		if (
			!in_array($answer, ['accepted', 'unmarked'], true)
			|| preg_match('/^[a-f0-9]{64}$/D', $textHash) !== 1
			|| !hash_equals(hash('sha256', $effectiveText), $textHash)
		)
		{
			throw new InvalidArgumentException('Invalid manifest item.');
		}
		$snapshot = [];
		foreach ($substitutions as $key => $value)
		{
			if (!is_string($key) || $key === '')
			{
				throw new InvalidArgumentException('Invalid substitutions.');
			}
			if (is_array($value))
			{
				if (!array_is_list($value))
				{
					throw new InvalidArgumentException('Invalid substitutions.');
				}
				$list = [];
				foreach ($value as $entry)
				{
					if (!is_string($entry))
					{
						throw new InvalidArgumentException('Invalid substitutions.');
					}
					$list[] = $entry;
				}
				$snapshot[$key] = $list;
			}
			elseif (($value === null || is_scalar($value)) && (!is_float($value) || is_finite($value)))
			{
				$snapshot[$key] = $value;
			}
			else
			{
				throw new InvalidArgumentException('Invalid substitutions.');
			}
		}
		$this->substitutions = $snapshot;
	}
}
