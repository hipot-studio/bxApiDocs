<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\Main\Result;

class ConvertRuleCommandResult extends Result
{
	public function __construct(
		public readonly ActivityData $activityData,
	)
	{
		parent::__construct();
	}
}
