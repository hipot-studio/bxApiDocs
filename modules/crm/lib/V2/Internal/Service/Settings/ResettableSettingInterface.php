<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Main\Result;

/**
 * @template TValue
 * @extends SettingInterface<TValue>
 * @internal
 */
interface ResettableSettingInterface extends SettingInterface
{
	public function reset(): Result;
}
