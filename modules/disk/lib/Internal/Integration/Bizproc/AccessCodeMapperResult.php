<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Bizproc;

/**
 * What {@see AccessCodeMapper} made of the recipients: the codes to write a right on, the values it could
 * not use and the ones it had to write under a wider code than the template chose.
 */
final class AccessCodeMapperResult
{
	/**
	 * @param string[] $codes
	 * @param array<string, string> $skipped Value as it came, with the reason it was dropped.
	 * @param array<string, string> $widened Value as it came, with the access code written for it.
	 */
	public function __construct(
		public readonly array $codes,
		public readonly array $skipped,
		public readonly array $widened,
	)
	{
	}
}
