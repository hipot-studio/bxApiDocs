<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

class RemoveAgents
{
	public function __invoke(array $template): void
	{
		\CAgent::RemoveAgent('CTasks::RepeatTaskByTemplateId(' . $template['ID'] . ');', 'tasks');
		\CAgent::RemoveAgent('CTasks::RepeatTaskByTemplateId(' . $template['ID'] . ', 0);', 'tasks');
		\CAgent::RemoveAgent('CTasks::RepeatTaskByTemplateId(' . $template['ID'] . ', 1);', 'tasks');
	}
}
