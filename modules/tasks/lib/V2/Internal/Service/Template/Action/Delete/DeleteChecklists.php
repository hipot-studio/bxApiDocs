<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\CheckList\Template\TemplateCheckListFacade;

class DeleteChecklists
{
	public function __invoke(array $template): void
	{
		TemplateCheckListFacade::deleteByEntityIdOnLowLevel($template['ID']);
	}
}
