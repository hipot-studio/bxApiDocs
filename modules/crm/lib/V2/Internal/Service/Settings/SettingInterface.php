<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SettingSchemaInterface;
use Bitrix\Main\Result;

/**
 * @template TValue
 * @internal
 */
interface SettingInterface
{
	public function getCode(): string;

	/** @return SettingDescription<TValue> */
	public function getDescription(): SettingDescription;

	/** @return SettingSchemaInterface<TValue> */
	public function getSchema(): SettingSchemaInterface;

	/** @return TValue */
	public function getValue(): mixed;

	/** @param TValue $value */
	public function set(mixed $value): Result;
}
