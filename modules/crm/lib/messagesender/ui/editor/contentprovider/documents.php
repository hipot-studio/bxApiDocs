<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor\ContentProvider;

use Bitrix\Crm\Integration\DocumentGeneratorManager;

final class Documents extends BaseContentProvider
{
	private ?string $provider = null;
	private bool $providerResolved = false;

	public function getId(): string
	{
		return 'documents';
	}

	public function isShown(): bool
	{
		return DocumentGeneratorManager::getInstance()->isDocumentButtonAvailable()
			&& $this->getProvider() !== null;
	}

	protected function getCustomData(): array
	{
		return [
			'moduleId' => 'crm',
			'provider' => $this->getProvider(),
			'value' => $this->getEntityId(),
		];
	}

	private function getProvider(): ?string
	{
		if ($this->providerResolved)
		{
			return $this->provider;
		}

		$this->providerResolved = true;

		$entityTypeId = $this->getEntityTypeId();
		if (!\CCrmOwnerType::IsDefined($entityTypeId))
		{
			return null;
		}

		$manager = DocumentGeneratorManager::getInstance();
		if (!$manager->isEnabled())
		{
			return null;
		}

		$this->provider = $manager->getCrmOwnerTypeProvider($entityTypeId);

		return $this->provider;
	}
}
