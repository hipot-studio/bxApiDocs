<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Marketing;

use Bitrix\Main\ArgumentException;

/**
 * What a fact is, as its source declares it: a stable code of the form "area.name", the type of its
 * value, whether it is answered per user or once for the portal, the values an enumeration fact may
 * take and the one channel whose rules alone may ask it, if any. Declaring a fact reads nothing.
 */
final class FactDefinition
{
	private const CODE_PATTERN = '/^[a-z][a-zA-Z0-9_]*(?:\.[a-z][a-zA-Z0-9_]*)+$/D';

	/**
	 * @param string[] $allowedValues the values of an EnumValue fact, in declared order
	 * @param string|null $channel the channel whose rules alone may ask the fact; null - any channel
	 * @throws ArgumentException
	 */
	public function __construct(
		public readonly string $code,
		public readonly FactType $type,
		public readonly bool $perUser,
		public readonly array $allowedValues = [],
		public readonly ?string $channel = null,
	)
	{
		if (preg_match(self::CODE_PATTERN, $code) !== 1)
		{
			throw new ArgumentException('Fact code must look like "area.name", got "' . $code . '"', 'code');
		}
	}
}
