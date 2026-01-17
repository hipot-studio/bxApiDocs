<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Command\Activity\Complex;

use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto;
use Bitrix\Main\Result;

class SaveSingleRuleCommandResult extends Result
{
	public function __construct(
		public readonly PortRuleDto $portRuleDto,
	)
	{
		parent::__construct();
	}
}
