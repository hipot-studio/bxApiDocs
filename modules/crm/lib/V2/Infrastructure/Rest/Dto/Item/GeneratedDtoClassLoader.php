<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Address\Address as OwnerAddressController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\OwnerAddressDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\CategoryDtoGenerator;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Dto\Generator;
use Bitrix\Rest\V3\Schema\ControllerDataManager;
use Bitrix\Rest\V3\Schema\GeneratedDto as RestGeneratedDto;
use UnexpectedValueException;

final class GeneratedDtoClassLoader
{
	private const BASE_NAMESPACE = 'Bitrix\\Crm\\V2\\Infrastructure\\Rest\\Dto\\';
	private const CLASS_SUFFIX = 'Dto';
	private const SMART_PROCESS_DTO_CLASS_PREFIX = __NAMESPACE__ . '\\EntityTypeSmartProcess';

	private static bool $isRegistered = false;

	public static function register(): void
	{
		if (self::$isRegistered)
		{
			return;
		}

		Loader::registerHandler([self::class, 'load']);
		self::$isRegistered = true;
	}

	public static function load(string $className): void
	{
		if (class_exists($className, false) || !self::isCandidate($className))
		{
			return;
		}

		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has(ControllerDataManager::class))
		{
			return;
		}

		$generatedItemDto = self::regenerateItemDto($className);
		if ($generatedItemDto !== null)
		{
			Generator::generateByDto($generatedItemDto);

			return;
		}

		$generatedCategoryDto = self::regenerateCategoryDto($className);
		if ($generatedCategoryDto !== null)
		{
			Generator::generateByDto($generatedCategoryDto);

			return;
		}

		$generatedOwnerAddressDto = self::regenerateOwnerAddressDto($className);
		if ($generatedOwnerAddressDto !== null)
		{
			Generator::generateByDto($generatedOwnerAddressDto);

			return;
		}

		/** @var ControllerDataManager $controllerDataManager */
		$controllerDataManager = $serviceLocator->get(ControllerDataManager::class);
		foreach ($controllerDataManager->getGeneratedDtosByModuleId('crm') as $generatedDto)
		{
			if (!$generatedDto instanceof RestGeneratedDto)
			{
				throw new UnexpectedValueException('REST cache must contain GeneratedDto instances.');
			}

			if ($generatedDto->getFQCN() === $className)
			{
				Generator::generateByDto($generatedDto);

				return;
			}
		}

	}

	private static function regenerateItemDto(string $className): ?RestGeneratedDto
	{
		$prefix = self::BASE_NAMESPACE . 'Item\\EntityType';
		if (
			!str_starts_with($className, $prefix)
			|| !str_ends_with($className, self::CLASS_SUFFIX)
		)
		{
			return null;
		}

		$code = substr($className, strlen($prefix), -strlen(self::CLASS_SUFFIX));
		$entityType = EntityType::fromCode($code);
		$entityTypeId = $entityType?->getId() ?? self::getSmartProcessEntityTypeId($className);
		if ($entityTypeId === null)
		{
			return null;
		}

		return (new ItemDtoGenerator())->generate($entityTypeId);
	}

	/**
	 * The category DTO of one entity type, regenerated the same way an item DTO is. Dynamic DTOs are
	 * deliberately kept out of the shared schema cache, so the loop below has nothing to find for them:
	 * a generated contract nobody regenerates on demand cannot be loaded at all, and every answer
	 * carrying it - `get`, `list`, `update` and the OpenAPI document - fails.
	 *
	 * One branch covers a smart process too, unlike the item DTO above: the code of the type carries
	 * its identifier (`CategorySmartProcess1032Dto`) and {@see EntityType::fromCode()} reads it back.
	 *
	 * A type without categories is refused rather than generated: its contract has no fields, and an
	 * empty one is an error to {@see Generator::generateByDto()}. The family publishes no route for
	 * such a type, so nothing asks for the class along the way - but a handler of the autoload must
	 * answer «not mine» for a name it cannot serve instead of throwing out of the autoload.
	 */
	private static function regenerateCategoryDto(string $className): ?RestGeneratedDto
	{
		$prefix = self::BASE_NAMESPACE . 'Category\\Category';
		if (
			!str_starts_with($className, $prefix)
			|| !str_ends_with($className, self::CLASS_SUFFIX)
		)
		{
			return null;
		}

		$code = substr($className, strlen($prefix), -strlen(self::CLASS_SUFFIX));
		$entityTypeId = EntityType::fromCode($code)?->getId();
		if ($entityTypeId === null)
		{
			return null;
		}

		if (!Container::getInstance()->getFactory($entityTypeId)?->isCategoriesSupported())
		{
			return null;
		}

		return (new CategoryDtoGenerator())->generate($entityTypeId);
	}

	/**
	 * The address contract of one entity type of the group `crm.{contact|company}.address.*`, regenerated
	 * the way the category DTO above is and for the same reason: a dynamic contract is kept out of the
	 * shared schema cache, so on a warm cache the loop below has nothing to find for it and every answer
	 * carrying the contract - `get`, `list`, both writes and the OpenAPI document - would fail.
	 *
	 * The name of the owner field is not decided here: it is read from
	 * {@see OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID}, the very map the configuration of
	 * the route takes the argument of its generator from. An entity type the group publishes no route for
	 * has no name there and is answered "not mine" rather than generated - a handler of the autoload is
	 * asked about every name in the namespace and must not throw out of one.
	 */
	private static function regenerateOwnerAddressDto(string $className): ?RestGeneratedDto
	{
		$prefix = self::BASE_NAMESPACE . 'Address\\OwnerAddress';
		if (
			!str_starts_with($className, $prefix)
			|| !str_ends_with($className, self::CLASS_SUFFIX)
		)
		{
			return null;
		}

		$code = substr($className, strlen($prefix), -strlen(self::CLASS_SUFFIX));
		$entityType = EntityType::fromCode($code);
		if ($entityType === null)
		{
			return null;
		}

		$ownerFieldName = OwnerAddressController::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[$entityType->getId()] ?? null;
		if ($ownerFieldName === null)
		{
			return null;
		}

		return (new OwnerAddressDtoGenerator())->generate($entityType->getId(), $ownerFieldName);
	}

	private static function getSmartProcessEntityTypeId(string $className): ?int
	{
		$pattern = '/^' . preg_quote(self::SMART_PROCESS_DTO_CLASS_PREFIX, '/') . '([1-9][0-9]*)'
			. preg_quote(self::CLASS_SUFFIX, '/') . '$/D';
		if (preg_match($pattern, $className, $matches) !== 1)
		{
			return null;
		}

		$entityTypeId = (int)$matches[1];

		return OwnerType::isPossibleSmartProcessTypeId($entityTypeId) ? $entityTypeId : null;
	}
	private static function isCandidate(string $className): bool
	{
		if (!str_starts_with($className, self::BASE_NAMESPACE))
		{
			return false;
		}

		$shortName = substr($className, strrpos($className, '\\') + 1);

		return str_ends_with($shortName, self::CLASS_SUFFIX)
			&& preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $shortName) === 1
		;
	}
}
