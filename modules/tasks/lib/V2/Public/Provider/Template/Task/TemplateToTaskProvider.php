<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Template\Task;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Config\ConvertConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\ToTaskConverter;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Task\TemplateToTaskParams;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\TemplateParams;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateProvider;

class TemplateToTaskProvider
{
	public function __construct(
		private readonly TemplateProvider $provider,
	)
	{

	}

	public function get(TemplateToTaskParams $params): ?Entity\Task
	{
		$template = $this->provider->get(new TemplateParams(
			templateId: $params->templateId,
			userId: $params->userId,
		));

		if ($template === null)
		{
			return null;
		}

		$convertConfig = new ConvertConfig(
			userId: $params->userId,
		);

		return (new ToTaskConverter($convertConfig))($template);
	}
}
