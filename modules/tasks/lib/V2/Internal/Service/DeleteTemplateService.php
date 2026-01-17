<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\DeleteService;

class DeleteTemplateService
{
	public function __construct(
		private readonly ConsistencyResolverInterface $consistencyResolver,
		private readonly DeleteService $deleteService,
	)
	{

	}

	public function delete(int $templateId, DeleteConfig $config, bool $useConsistency = true): void
	{
		if ($useConsistency)
		{
			$this->consistencyResolver->resolve('template.delete')->wrap(
				fn () => $this->deleteService->delete($templateId, $config)
			);
		}
		else
		{
			$this->deleteService->delete($templateId, $config);
		}
	}
}
