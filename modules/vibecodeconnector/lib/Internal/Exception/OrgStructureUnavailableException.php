<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Exception;

final class OrgStructureUnavailableException extends CodedSystemException
{
	public const MODULE_UNAVAILABLE = 'STRUCTURE_MODULE_UNAVAILABLE';
	public const NOT_INITIALIZED = 'STRUCTURE_NOT_INITIALIZED';

	public static function moduleUnavailable(): self
	{
		return new self('Module humanresources is not available on this portal', self::MODULE_UNAVAILABLE);
	}

	public static function notInitialized(): self
	{
		return new self('Org structure is not initialized on this portal', self::NOT_INITIALIZED);
	}
}
