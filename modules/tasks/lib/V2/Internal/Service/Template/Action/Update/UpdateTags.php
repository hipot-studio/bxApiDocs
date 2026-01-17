<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Control\TemplateTag;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;

class UpdateTags
{
	public function __construct(
		private readonly UpdateConfig $config,
	)
	{
	}

	public function __invoke(array $fields): void
	{
		(new TemplateTag($fields['ID'], $this->config->getUserId()))->set($fields);
	}
}
