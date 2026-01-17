<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity\Result;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Main\Result;

class ActivityAiDescriptionResult extends Result
{
	public function __construct(
		public readonly string $code,
		public readonly SettingCollection $settings,
	)
	{
		parent::__construct();
	}
}