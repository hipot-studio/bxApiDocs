<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\TaskBuilder;

class ConvertSubTasks implements ConvertFieldInterface
{
	public function __invoke(Template $template, TaskBuilder $taskBuilder): void
	{
		$taskBuilder->set('containsSubTasks', $template->containsSubTemplates ?? false);
	}
}
