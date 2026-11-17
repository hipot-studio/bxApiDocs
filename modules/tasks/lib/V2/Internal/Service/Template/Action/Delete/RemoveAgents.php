<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Replication\Agent\AfterCompletionAgent;

class RemoveAgents
{
	public function __invoke(array $template): void
	{
		AfterCompletionAgent::removeAll((int)$template['ID']);

		\CAgent::RemoveAgent('CTasks::RepeatTaskByTemplateId(' . $template['ID'] . ');', 'tasks');
		\CAgent::RemoveAgent('CTasks::RepeatTaskByTemplateId(' . $template['ID'] . ', 0);', 'tasks');
		\CAgent::RemoveAgent('CTasks::RepeatTaskByTemplateId(' . $template['ID'] . ', 1);', 'tasks');
	}
}
