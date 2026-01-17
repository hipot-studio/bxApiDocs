<?php

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\TaskBuilder;

interface ConvertFieldInterface
{
	//todo: Ideally, the handler should not depend on TaskBuilder
	public function __invoke(Template $template, TaskBuilder $taskBuilder): void;
}
