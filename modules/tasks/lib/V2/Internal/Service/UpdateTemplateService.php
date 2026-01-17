<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\UpdateUserFields;
use Bitrix\Tasks\V2\Internal\Service\Template\UpdateService;

class UpdateTemplateService
{
	public function __construct(
		private readonly UpdateService $updateService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly TemplateRepositoryInterface $templateRepository,
	)
	{

	}

	public function update(Template $template, UpdateConfig $config, bool $useConsistency = true): Template
	{
		if ($useConsistency)
		{
			[$template, $fields] = $this->consistencyResolver->resolve('template.update')->wrap(
				fn (): array => $this->updateService->update($template, $config)
			);
		}
		else
		{
			[$template, $fields] = $this->updateService->update($template, $config);
		}

		(new UpdateUserFields($config))($fields);

		return $this->templateRepository->getById($template->getId());
	}
}
