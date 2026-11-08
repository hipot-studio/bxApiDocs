<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor\Scene;

use Bitrix\Crm\MessageSender\CustomTemplate\CrmTemplateTargetId;
use Bitrix\Crm\MessageSender\CustomTemplate\CrmZone;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\Scene;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel;

Loader::requireModule('messageservice');

abstract class BaseScene extends Scene
{
	/**
	 * Human-readable scene name for UI surfaces (e.g. the custom-templates list grid).
	 * Each concrete scene owns its own localization.
	 */
	abstract public function getName(): string;

	public function shouldShowInTemplateBindingLabels(): bool
	{
		return true;
	}

	public function buildTemplateBinding(Context $context): ?TemplateBinding
	{
		$entityTypeId = $context->getCustomDataInt('entityTypeId');
		if ($entityTypeId === null || $entityTypeId <= 0)
		{
			return null;
		}

		return new TemplateBinding(
			CrmZone::ID,
			$this->getId(),
			CrmTemplateTargetId::fromEditorContext($context)->toString(),
		);
	}

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
