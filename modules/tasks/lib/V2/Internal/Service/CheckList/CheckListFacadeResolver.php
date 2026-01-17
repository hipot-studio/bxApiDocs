<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Tasks\CheckList\CheckListFacade;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\CheckList\Template\TemplateCheckListFacade;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;

class CheckListFacadeResolver
{
	/**
	 * @return class-string<CheckListFacade>
	 */
	public function resolveByType(Type $type): string
	{
		return match ($type)
		{
			Type::Task => TaskCheckListFacade::class,
			Type::Template => TemplateCheckListFacade::class,
		};
	}
}
