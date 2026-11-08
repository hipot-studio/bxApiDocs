<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SettingSchemaInterface;
use Bitrix\Main\Result;

/**
 * @template TValue
 * @implements SettingInterface<TValue>
 * @internal
 */
abstract class AbstractSetting implements SettingInterface
{
	final public function getSchema(): SettingSchemaInterface
	{
		return $this->getDescription()->schema;
	}

	final public function set(mixed $value): Result
	{
		$result = $this->getSchema()->validate($value);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $this->apply($value);
	}

	/** @param TValue $value */
	abstract protected function apply(mixed $value): Result;
}
