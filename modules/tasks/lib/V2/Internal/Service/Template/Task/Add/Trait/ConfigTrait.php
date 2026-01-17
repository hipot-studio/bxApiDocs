<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Trait;

use Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Config\AddTaskConfig;

trait ConfigTrait
{
	public function __construct(
		private readonly AddTaskConfig $config,
	)
	{

	}
}
