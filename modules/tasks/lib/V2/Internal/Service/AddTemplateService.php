<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\AddUserFields;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\AddService;

class AddTemplateService
{
	public function __construct(
		private readonly AddService $addService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
	)
	{

	}
	
	public function add(Template $template, AddConfig $config, bool $useConsistency = true): Template
	{
		if ($useConsistency)
		{
			[$template, $fields] = $this->consistencyResolver->resolve('template.add')->wrap(
				fn (): array => $this->addService->add($template, $config)
			);
		}
		else
		{
			[$template, $fields] = $this->addService->add($template, $config);
		}

		(new AddUserFields($config))($fields);

		return $template;
	}
}
