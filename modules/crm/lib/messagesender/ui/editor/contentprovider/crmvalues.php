<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\Service\Container;

final class CrmValues extends BaseContentProvider
{
	public function getId(): string
	{
		return 'crmValues';
	}

	public function isShown(): bool
	{
		$entityTypeId = $this->getEntityTypeId();
		if (!\CCrmOwnerType::IsDefined($entityTypeId))
		{
			return false;
		}

		return DocumentGeneratorManager::getInstance()->isEnabled()
			&& Container::getInstance()->getFactory($entityTypeId)?->isDocumentGenerationSupported();
	}

	protected function getCustomData(): array
	{
		return [
			'placeholdersOptions' => [
				'entityTypeId' => $this->getEntityTypeId(),
				'entityId' => $this->getEntityId(),
				'categoryId' => $this->getCategoryId(),
			],
		];
	}
}
