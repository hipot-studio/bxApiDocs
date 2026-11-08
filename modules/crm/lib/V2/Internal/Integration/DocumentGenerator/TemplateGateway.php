<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\DocumentGenerator;

use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\DocumentGenerator\Driver;
use Bitrix\Main\Loader;

/**
 * The single point CRM V2 asks the document generator about the templates of a smart process.
 *
 * The map of providers and the address builder are not repeated in V2: the legacy CRM facade over
 * the generator is asked for them instead.
 *
 * @internal
 */
class TemplateGateway
{
	/**
	 * The address at which the templates of this type are opened, or null when the user has no such
	 * address on this portal.
	 */
	public function getAddTemplateUrlForEntityType(int $entityTypeId, int $userId): ?string
	{
		if (!$this->isGeneratorEnabled())
		{
			return null;
		}
		if (!$this->canModifyTemplates($userId))
		{
			return null;
		}

		// the provider is resolved before the address is built: the builder answers an empty
		// provider with the general list of templates rather than a refusal
		$provider = $this->findEntityTypeProvider($entityTypeId);
		if ($provider === null || $provider === '')
		{
			return null;
		}

		$url = $this->buildAddTemplateUrl($provider);

		return $url === false ? null : $url;
	}

	protected function isGeneratorEnabled(): bool
	{
		return $this->manager()->isEnabled();
	}

	protected function canModifyTemplates(int $userId): bool
	{
		if (!Loader::includeModule('documentgenerator'))
		{
			return false;
		}

		return Driver::getInstance()->getUserPermissions($userId)->canModifyTemplates();
	}

	/**
	 * @return string|null the data provider of the type, empty for a type that keeps no documents
	 */
	protected function findEntityTypeProvider(int $entityTypeId): ?string
	{
		return $this->manager()->getCrmOwnerTypeProvider($entityTypeId);
	}

	/**
	 * @return string|false false when the portal serves no templates page at all
	 */
	protected function buildAddTemplateUrl(string $provider): string|false
	{
		return $this->manager()->getAddTemplateUrl($provider);
	}

	private function manager(): DocumentGeneratorManager
	{
		return DocumentGeneratorManager::getInstance();
	}
}
