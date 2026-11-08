<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\ProcessData;

use Bitrix\Bizproc\Public\Feature\ProcessData\ProcessDataFlag;

final class ProcessDataFeature
{
	public static function isEnabled(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(ProcessDataFlag::class);
	}
}
