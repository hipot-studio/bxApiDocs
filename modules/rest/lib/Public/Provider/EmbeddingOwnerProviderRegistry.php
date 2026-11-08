<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;

class EmbeddingOwnerProviderRegistry
{
	public function resolve(string $moduleId, string $entityType): ?EmbeddingOwnerProviderInterface
	{
		$moduleId = EmbeddingOwnerResolver::canonicalizeOwnerPart($moduleId);
		$entityType = EmbeddingOwnerResolver::canonicalizeOwnerPart($entityType);

		if (empty($moduleId) || empty($entityType))
		{
			return null;
		}

		try
		{
			if (!Loader::includeModule($moduleId))
			{
				return null;
			}
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return null;
		}

		$config = Configuration::getInstance($moduleId)->get('rest.embedding.ownerProviders');
		if (!is_array($config) || empty($config))
		{
			return null;
		}

		$fqcn = $config[$entityType] ?? null;
		if (!is_string($fqcn) || !class_exists($fqcn))
		{
			return null;
		}

		$instance = ServiceLocator::getInstance()->get($fqcn);

		return $instance instanceof EmbeddingOwnerProviderInterface ? $instance : null;
	}
}
