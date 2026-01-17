<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\AddTemplateService;

class AddTemplateHandler
{

	public function __construct(
		private readonly AddTemplateService $addTemplateService,
	)
	{
	}

	public function __invoke(AddTemplateCommand $command): Entity\Template
	{
		return $this->addTemplateService->add($command->template, $command->config);
	}
}
