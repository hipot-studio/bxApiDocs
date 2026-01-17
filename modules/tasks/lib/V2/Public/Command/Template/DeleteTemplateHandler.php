<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template;

use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\DeleteTemplateService;

class DeleteTemplateHandler
{
	public function __construct(
		private readonly DeleteTemplateService $deleteTemplateService,
	)
	{
	}

	public function __invoke(DeleteTemplateCommand $command): void
	{
		$this->deleteTemplateService->delete($command->templateId, $command->config);
	}
}
