<?php

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

final class Documents extends ContentProvider
{
	public function getKey(): string
	{
		return 'documents';
	}

	public function isEnabled(): bool
	{
		return DocumentGeneratorManager::getInstance()->isDocumentButtonAvailable() && $this->getProvider();
	}

	private function getProvider(): ?string
	{
		$entityTypeId = $this->getContext()->getEntityTypeId();
		if ($entityTypeId === null)
		{
			return null;
		}

		$manager = DocumentGeneratorManager::getInstance();
		if (!$manager->isEnabled())
		{
			return null;
		}

		return $manager->getCrmOwnerTypeProvider($entityTypeId);
	}

	public function jsonSerialize(): array
	{
		return [
			...parent::jsonSerialize(),
			'provider' => $this->getProvider(),
		];
	}
}
