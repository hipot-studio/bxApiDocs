<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\Control\TemplateTag;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;

class AddTags
{
	public function __construct(
		private readonly AddConfig $config,
	)
	{

	}

	public function __invoke(array $fields): void
	{
		(new TemplateTag($fields['ID'], $this->config->getUserId()))->add($fields);
	}
}
