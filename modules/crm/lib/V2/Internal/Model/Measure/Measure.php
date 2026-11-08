<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\Measure;

/**
 * @internal
 */
final class Measure
{
	public function __construct(
		public readonly int $id,
		public readonly int $code,
		public readonly ?string $title,
		public readonly ?string $symbolRus,
		public readonly ?string $symbolIntl,
		public readonly ?string $symbolLetterIntl,
		public readonly bool $isDefault,
	)
	{
	}
}
