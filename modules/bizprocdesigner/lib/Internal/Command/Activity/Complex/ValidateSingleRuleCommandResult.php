<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

class ValidateSingleRuleCommandResult extends \Bitrix\Main\Result
{
	public function __construct(
		public bool $isFilled = false,
	)
	{
		parent::__construct();
	}
}
