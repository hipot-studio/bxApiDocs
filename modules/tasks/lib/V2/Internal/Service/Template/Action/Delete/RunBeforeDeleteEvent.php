<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

class RunBeforeDeleteEvent
{
	public function __invoke(array $fullTemplateData): bool
	{
		foreach (GetModuleEvents('tasks', 'OnBeforeTaskTemplateDelete', true) as $arEvent)
		{
			if (ExecuteModuleEventEx($arEvent, [$fullTemplateData['ID'], $fullTemplateData]) === false)
			{
				return false;
			}
		}

		return true;
	}
}
