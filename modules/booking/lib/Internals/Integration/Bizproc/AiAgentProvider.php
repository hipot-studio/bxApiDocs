<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Bizproc;

use Bitrix\Main\Loader;

class AiAgentProvider
{
	private const ACTION_START = 'start';
	private const ACTION_COPY_AND_START = 'copyAndStart';

	private const ERROR_DUPLICATE_TEMPLATES = 'DUPLICATE_TEMPLATES';

	public function __construct(
		private readonly AiAgentTemplateQuery $templateQuery,
	)
	{
	}

	public function getAiAgentData(): AiAgentDataDto|null
	{
		if (!Loader::includeModule('bizproc'))
		{
			return null;
		}

		$userTemplateIds = $this->templateQuery->findUserTemplateIds();
		if (count($userTemplateIds) > 1)
		{
			return new AiAgentDataDto(null, null, self::ERROR_DUPLICATE_TEMPLATES);
		}

		if (count($userTemplateIds) === 1)
		{
			return new AiAgentDataDto($userTemplateIds[0], self::ACTION_START, null);
		}

		$systemTemplateId = $this->templateQuery->findSystemTemplateId();
		if ($systemTemplateId !== null)
		{
			return new AiAgentDataDto($systemTemplateId, self::ACTION_COPY_AND_START, null);
		}

		return null;
	}
}
