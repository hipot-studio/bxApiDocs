<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Integration\Recyclebin\Template;
use Bitrix\Tasks\V2\Internal\Integration\Recyclebin\Exception\MoveToRecyclebinException;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;

class MoveToRecyclebin
{
	public function __construct(
		private readonly DeleteConfig $config,
	)
	{

	}

	public function __invoke(array $fullTemplateData): void
	{
		if (!Loader::includeModule('recyclebin'))
		{
			$this->config->getRuntime()->setMovedToRecyclebin(false);

			return;
		}

		$result = Template::OnBeforeDelete(
			$fullTemplateData['ID'],
			$fullTemplateData
		);

		if (!$result->isSuccess())
		{
			throw new MoveToRecyclebinException($result->getError()?->getMessage());
		}

		$this->config->getRuntime()->setMovedToRecyclebin(true);
	}
}
