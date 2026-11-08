<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Settings\Schema;

use Bitrix\Crm\V2\Internal\Entity\Settings\Validation\SettingErrorCode;
use Bitrix\Crm\V2\Internal\Entity\Settings\Validation\SettingValidationError;
use Bitrix\Main\Result;

/**
 * @implements SettingSchemaInterface<bool>
 * @internal
 */
final class BooleanSchema implements SettingSchemaInterface
{
	public function validate(mixed $value): Result
	{
		$result = new Result();

		if (!is_bool($value))
		{
			$result->addError(
				new SettingValidationError(
					'Value must be a boolean.',
					SettingErrorCode::INVALID_VALUE_TYPE,
				),
			);
		}

		return $result;
	}
}
