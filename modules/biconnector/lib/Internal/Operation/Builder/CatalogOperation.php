<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Schema\ControllerData;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Schema\SchemaManager;

/**
 * Methods of the builder family the REST V3 schema publishes, each with the refusal codes it can answer with.
 *
 * A published method is an action of a controller the schema found in the module, enabled, neither
 * hidden nor deprecated, under the family prefix. The field methods the schema adds to a resource belong
 * to the system controller of the rest module and are left out, as is the catalog itself. Types of the
 * arguments and the shape of the answer stay in the schema and are not repeated here.
 *
 * A controller the entry point reports closed is left out entirely; the operation does not read flags.
 *
 * Data: `methods` — a list of `{method, error_codes}` sorted by method name.
 */
final class CatalogOperation
{
	public const MODULE_ID = 'biconnector';
	public const FAMILY_PREFIX = 'biconnector.builder.';
	public const CATALOG_METHOD = 'biconnector.builder.catalog.get';

	private readonly SchemaManager $schemaManager;

	public function __construct(?SchemaManager $schemaManager = null)
	{
		$this->schemaManager = $schemaManager ?? ServiceLocator::getInstance()->get(SchemaManager::class);
	}

	/**
	 * @param \Closure(string $controllerFqcn, string $method): FailureCode[] $failureCodesOf Codes the entry
	 *     point declares for an action of a controller; the operation does not know how the entry marks them.
	 * @param (\Closure(string $controllerFqcn): bool)|null $isControllerOpen Whether the methods of a controller
	 *     may be listed for the caller; null opens every controller.
	 */
	public function execute(\Closure $failureCodesOf, ?\Closure $isControllerOpen = null): Result
	{
		$methods = [];
		$openness = [];
		foreach ($this->getPublishedMethods() as $description)
		{
			if ($isControllerOpen !== null)
			{
				$controllerFqcn = $description->controllerFqcn;
				$openness[$controllerFqcn] ??= (bool)$isControllerOpen($controllerFqcn);
				if (!$openness[$controllerFqcn])
				{
					continue;
				}
			}

			$codes = array_map(
				static fn(FailureCode $code): string => $code->value,
				$failureCodesOf($description->controllerFqcn, $description->method),
			);

			$methods[$description->actionUri] = [
				'method' => $description->actionUri,
				'error_codes' => array_values(array_unique($codes)),
			];
		}
		ksort($methods);

		return (new Result())->setData(['methods' => array_values($methods)]);
	}

	/**
	 * @return MethodDescription[]
	 */
	private function getPublishedMethods(): array
	{
		$controllers = $this->schemaManager->getControllersByModules()[self::MODULE_ID] ?? [];

		$methods = [];
		/** @var ControllerData $controllerData */
		foreach ($controllers as $controllerData)
		{
			if (!$controllerData->isEnabled())
			{
				continue;
			}

			foreach ($controllerData->getMethods() as $description)
			{
				if (self::isPublished($description))
				{
					$methods[] = $description;
				}
			}
		}

		return $methods;
	}

	private static function isPublished(MethodDescription $description): bool
	{
		return $description->controllerFqcn !== null
			&& $description->isEnabled
			&& !$description->isHidden
			&& !$description->isDeprecated
			&& str_starts_with($description->actionUri, self::FAMILY_PREFIX)
			&& $description->actionUri !== self::CATALOG_METHOD
		;
	}
}
