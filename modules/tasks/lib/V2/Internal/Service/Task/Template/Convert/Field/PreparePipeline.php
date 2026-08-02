<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Config\ConvertConfig;

class PreparePipeline
{
	public function __construct(
		private readonly ConvertConfig $config,
		private readonly array $preparersClasses,
	)
	{
	}

	public function __invoke(Entity\Template $template, Entity\Task $task): Entity\Template
	{
		foreach ($this->preparersClasses as $class)
		{
			if (!is_subclass_of($class, PrepareFieldInterface::class))
			{
				continue;
			}

			$template = (new $class($this->config))($template, $task);
		}

		return $template;
	}
}
