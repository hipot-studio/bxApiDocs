<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Forward;

use Bitrix\Im\V2\Error;

class ForwardError extends Error
{
	public static function fromError(\Bitrix\Main\Error $original, ?string $uuid): self
	{
		$customData = (array)($original->getCustomData() ?? []);
		$customData['uuid'] = $uuid;

		return new self(
			(string)$original->getCode(),
			$original->getMessage(),
			$customData,
		);
	}
}
