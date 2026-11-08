<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class LayoutAudience
{
	public const MAX_ITEMS = 1000;

	/**
	 * @param list<AccessCode> $accessCodes
	 */
	private function __construct(private readonly array $accessCodes)
	{
	}

	/**
	 * Creates a normalized custom layout audience.
	 *
	 * @param array<array-key, AccessCode> $accessCodes
	 */
	public static function create(array $accessCodes): Result
	{
		return self::normalize($accessCodes, self::MAX_ITEMS);
	}

	/**
	 * @param array<array-key, AccessCode> $accessCodes
	 */
	private static function normalize(array $accessCodes, ?int $maxItems = null): Result
	{
		$normalized = [];
		foreach ($accessCodes as $accessCode)
		{
			if (!$accessCode instanceof AccessCode)
			{
				return self::invalidResult();
			}

			$normalized[$accessCode->getValue()] = $accessCode;
			if ($maxItems !== null && count($normalized) > $maxItems)
			{
				return self::invalidResult();
			}
		}

		ksort($normalized, SORT_STRING);

		return (new Result())->setData([
			'audience' => new self(array_values($normalized)),
		]);
	}

	/**
	 * Restores an audience from canonical persisted access codes.
	 *
	 * @param array<array-key, string> $values
	 */
	public static function fromValues(array $values): Result
	{
		$accessCodes = [];
		foreach ($values as $value)
		{
			if (!is_string($value))
			{
				return self::invalidResult();
			}

			$accessCodeResult = AccessCode::fromValue($value);
			if (!$accessCodeResult->isSuccess())
			{
				return self::invalidResult();
			}

			$accessCodes[] = $accessCodeResult->getData()['accessCode'];
		}

		return self::normalize($accessCodes);
	}

	/**
	 * Returns the normalized access codes.
	 *
	 * @return list<AccessCode>
	 */
	public function getAccessCodes(): array
	{
		return $this->accessCodes;
	}

	/**
	 * Returns the normalized input accepted by the internal layout service.
	 *
	 * @return list<array{type: string, id: int}>
	 */
	public function toArray(): array
	{
		return array_map(
			static fn(AccessCode $accessCode): array => $accessCode->toArray(),
			$this->accessCodes,
		);
	}

	private static function invalidResult(): Result
	{
		return (new Result())->addError(
			new Error('The layout audience is invalid.', 'INVALID_LAYOUT_AUDIENCE'),
		);
	}
}
