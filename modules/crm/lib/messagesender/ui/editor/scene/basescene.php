<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\Scene;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;

Loader::requireModule('messageservice');

abstract class BaseScene extends Scene
{
	public function filterViewChannels(array $viewChannels, Context $context): array
	{
		$entityTypeId = $context->getCustomDataInt('entityTypeId');

		if (
			$entityTypeId !== null
			&& !Container::getInstance()->getFactory($entityTypeId)?->isDocumentGenerationSupported()
		)
		{
			return array_filter(
				$viewChannels,
				static fn(ViewChannel $vc): bool => !$vc->getBackend()->isTemplatesBased(),
			);
		}

		return $viewChannels;
	}
}
