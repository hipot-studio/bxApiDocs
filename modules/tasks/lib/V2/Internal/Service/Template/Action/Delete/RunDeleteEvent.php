<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

class RunDeleteEvent
{
	public function __invoke(array $fullTemplateData): void
	{
		foreach (GetModuleEvents('tasks', 'OnTaskTemplateDelete', true) as $arEvent)
		{
			if (ExecuteModuleEventEx($arEvent, [$fullTemplateData['ID'], $fullTemplateData]) === false)
			{
				return;
			}
		}
	}
}
