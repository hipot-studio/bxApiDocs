<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Dto;

final readonly class EmbeddingOpenContext
{
	private function __construct(
		public string $placement,
		public int $placementId,
		public string $placementOptions,
	) {}

	public static function fromFields(array $fields): self
	{
		if (
			count($fields) !== 3
			|| !is_string($fields['PLACEMENT'] ?? null)
			|| $fields['PLACEMENT'] === ''
			|| !is_string($fields['PLACEMENT_ID'] ?? null)
			|| preg_match('/^[0-9]+$/D', $fields['PLACEMENT_ID']) !== 1
			|| (int)$fields['PLACEMENT_ID'] <= 0
			|| (string)(int)$fields['PLACEMENT_ID'] !== $fields['PLACEMENT_ID']
			|| !is_string($fields['PLACEMENT_OPTIONS'] ?? null)
		)
		{
			throw new \InvalidArgumentException('Invalid embedding context');
		}

		try
		{
			$options = json_decode($fields['PLACEMENT_OPTIONS'], false, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			throw new \InvalidArgumentException('Invalid embedding context');
		}

		if (!($options instanceof \stdClass))
		{
			throw new \InvalidArgumentException('Invalid embedding context');
		}

		return new self(
			$fields['PLACEMENT'],
			(int)$fields['PLACEMENT_ID'],
			str_replace('|', '\\u007c', $fields['PLACEMENT_OPTIONS']),
		);
	}
}
