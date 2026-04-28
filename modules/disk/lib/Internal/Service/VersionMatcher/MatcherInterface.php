<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\VersionMatcher;

interface MatcherInterface
{
	/**
	 * @param mixed $version
	 * @param mixed $template
	 * @return bool
	 */
	public static function match(mixed $version, mixed $template): bool;
}
