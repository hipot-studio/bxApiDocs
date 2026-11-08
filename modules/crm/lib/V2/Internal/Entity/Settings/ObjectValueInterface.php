<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Settings;

/**
 * @internal
 */
interface ObjectValueInterface
{
	/** @return array<string, mixed> */
	public function toArray(): array;
}
