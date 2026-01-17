<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Activity;

class ActivityNameGeneratorService
{
	public function generate(): string
	{
		return 'A'.mt_rand(10000, 99999)
			.'_'.mt_rand(10000, 99999)
			.'_'.mt_rand(10000, 99999)
			.'_'.mt_rand(10000, 99999)
		;
	}
}
