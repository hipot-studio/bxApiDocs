<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Control\Template;
use Bitrix\Tasks\Internals\DataBase\Tree\TargetNodeNotFoundException;
use Bitrix\Tasks\Internals\Task\Template\DependenceTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\DeleteTemplateService;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;

class DeleteSubTemplates
{
	public function __construct(
		private readonly DeleteConfig $config
	)
	{

	}
	public function __invoke(array $template): void
	{
		if (!$this->config->isDeleteSubTemplates())
		{
			return;
		}

		$subTemplatesBdResult = DependenceTable::getSubTree(
			$template['ID'],
			['select' => ['ID' => 'TEMPLATE_ID']],
			['INCLUDE_SELF' => false]
		);

		$deleteService = Container::getInstance()->get(DeleteTemplateService::class);

		$config = new DeleteConfig(
			userId: $this->config->getUserId(),
			unsafeDelete: $this->config->isUnsafeDelete(),
			deleteSubTemplates: $this->config->isDeleteSubTemplates(),
		);

		while ($subTemplateItem = $subTemplatesBdResult->fetch())
		{
			$deleteService->delete((int)$subTemplateItem['ID'], $config, false);
		}
	}
}
