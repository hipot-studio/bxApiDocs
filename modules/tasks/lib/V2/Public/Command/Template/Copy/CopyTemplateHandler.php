<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Copy;

use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\CopyTemplateService;

class CopyTemplateHandler
{
	public function __construct(
		private readonly CopyTemplateService $copyTemplateService,
	)
	{

	}

	/**
	 * @throws TemplateNotFoundException
	 * @throws TemplateAddException
	 */
	public function __invoke(CopyTemplateCommand $command): Entity\Template
	{
		return $this->copyTemplateService->copy(
			templateData: $command->templateData,
			config: $command->config,
		);
	}
}
