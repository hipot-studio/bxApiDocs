<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Trait\ConfigTrait;

class PrepareReplication implements PrepareFieldInterface
{
	use ConfigTrait;

	public function __invoke(Entity\Template $template, Entity\Task $task): Entity\Template
	{
		if (!$this->config->withReplication)
		{
			return $template;
		}

		if ($task->replicateParams === null)
		{
			return $template;
		}

		return $template->cloneWith([
			'replicate' => true,
			'replicateParams' => $task->replicateParams,
		]);
	}
}
