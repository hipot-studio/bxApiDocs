<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

/**
 * @internal
 */
final class Scope
{
	public const FIELD_ID = 'ID';
	public const FIELD_NAME = 'NAME';
	public const FIELD_LAYOUT = 'CONFIG';
	public const FIELD_AUTO_APPLY_SCOPE = 'AUTO_APPLY_SCOPE';
	public const FIELD_ON_ADD = 'ON_ADD';
	public const FIELD_ON_UPDATE = 'ON_UPDATE';

	/**
	 * @var string[]
	 */
	public readonly array $accessCodes;

	/**
	 * @param array<int, array<string, mixed>> $layout
	 * @param array<int, mixed> $accessCodes
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly array $layout,
		public readonly bool $autoApplyScope,
		public readonly bool $onAdd,
		public readonly bool $onUpdate,
		array $accessCodes,
	)
	{
		$this->accessCodes = self::normalizeAccessCodes($accessCodes);
	}

	/**
	 * @param array<int, mixed> $accessCodes
	 * @return string[]
	 */
	private static function normalizeAccessCodes(array $accessCodes): array
	{
		$normalized = [];

		foreach ($accessCodes as $accessCode)
		{
			if (!is_string($accessCode))
			{
				continue;
			}

			$accessCode = trim($accessCode);
			if ($accessCode !== '')
			{
				$normalized[$accessCode] = $accessCode;
			}
		}

		ksort($normalized, SORT_STRING);

		return array_values($normalized);
	}
}
