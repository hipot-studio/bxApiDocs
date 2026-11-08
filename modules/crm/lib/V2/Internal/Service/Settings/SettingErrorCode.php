<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

/**
 * @internal
 */
final class SettingErrorCode
{
	public const INVALID_VALUE_TYPE = 'INVALID_VALUE_TYPE';
	public const VALUE_OUT_OF_RANGE = 'VALUE_OUT_OF_RANGE';
	public const VALUE_STEP_MISMATCH = 'VALUE_STEP_MISMATCH';
	public const VALUE_NOT_ALLOWED = 'VALUE_NOT_ALLOWED';
	public const DUPLICATE_VALUE = 'DUPLICATE_VALUE';
	public const REQUIRED_FIELD_MISSING = 'REQUIRED_FIELD_MISSING';
	public const UNKNOWN_FIELD = 'UNKNOWN_FIELD';
}
