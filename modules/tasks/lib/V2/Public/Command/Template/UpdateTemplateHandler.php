<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\UpdateTemplateService;

class UpdateTemplateHandler
{
	public function __construct(
		private readonly UpdateTemplateService $updateTemplateService,
	)
	{
	}

	public function __invoke(UpdateTemplateCommand $command): Entity\Template
	{
		return $this->updateTemplateService->update($command->template, $command->config);
	}
}
