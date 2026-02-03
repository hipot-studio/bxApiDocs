<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Integration\RunMessenger;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;

class RunIntegration
{
	use ConfigTrait;

	public function __invoke(array $fields, ?Task\Source $source = null): void
	{
		(new RunMessenger($this->config))($fields, $source);
	}
}
