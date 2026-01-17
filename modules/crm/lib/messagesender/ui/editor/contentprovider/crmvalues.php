<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;
use Bitrix\Crm\Service\Container;

final class CrmValues extends ContentProvider
{
	public function getKey(): string
	{
		return 'crmValues';
	}

	public function isEnabled(): bool
	{
		$manager = DocumentGeneratorManager::getInstance();

		return (
			$manager->isEnabled()
			&& $this->getContext()->getEntityTypeId() !== null
			&& Container::getInstance()->getFactory($this->getContext()->getEntityTypeId())?->isDocumentGenerationSupported()
		);
	}
}
