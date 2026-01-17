<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Config\ConvertConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\TaskBuilder;

class ConvertPipeline
{
	public function __construct(
		private readonly ConvertConfig $config,
		private readonly array $convertersClasses
	)
	{

	}

	public function __invoke(Template $template, TaskBuilder $taskBuilder): void
	{
		foreach ($this->convertersClasses as $class)
		{
			if (!is_subclass_of($class, ConvertFieldInterface::class))
			{
				continue;
			}

			(new $class($this->config))($template, $taskBuilder);
		}
	}
}
