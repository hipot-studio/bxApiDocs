<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Schema;

use Bitrix\Main\Result;

/**
 * @template TValue
 * @internal
 */
interface SettingSchemaInterface
{
	/** @param TValue $value */
	public function validate(mixed $value): Result;
}
