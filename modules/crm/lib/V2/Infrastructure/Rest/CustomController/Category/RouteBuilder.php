<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category;

use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\CategoryDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category\StageDto;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * The routes of the category family for every entity type the portal has them enabled on.
 *
 * Deal, Company and Contact are always there, while smart processes come and go with the portal, so
 * the family cannot be a static list: it is built from the actual composition of the types. A method
 * exists only where the capability behind it does - the category methods where the entity type has
 * categories, the stage methods where it has stages as well - rather than being registered
 * everywhere and answering with an error at runtime.
 *
 * Building this walks the types of the portal, which is affordable exactly because the schema is
 * built whole and cached whole ({@see \Bitrix\Rest\V3\Schema\ControllerDataManager}). The walk reads
 * the map of types that is already cached for a day and touches
 * {@see EntityTypeSettings::of()} once per type; nothing here may grow into a query per type.
 */
class RouteBuilder
{
	/**
	 * The feature the whole family is behind. It is named here rather than in
	 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider} so that the routes and
	 * their gate cannot drift apart: the gate is keyed by the very names this builder returns, and a
	 * route of a smart process that slipped out of the map would be permanently open -
	 * {@see \Bitrix\Crm\Feature::enabled()} answers `true` for a route it knows nothing about.
	 *
	 * @var class-string<\Bitrix\Crm\Feature\BaseFeature>
	 */
	public const FEATURE = RestV3CrudDeal::class;

	/**
	 * Named rather than accumulated from the segments of the route: the segments would ask for
	 * `crm.deal.category`, which is not a registered scope.
	 */
	private const SCOPES = ['crm'];

	private const MCP_TOOL_SET_CODE = 'crm_rest_v3_category';

	private const STATIC_ENTITY_TYPE_IDS = [OwnerType::DEAL, OwnerType::COMPANY, OwnerType::CONTACT];

	/** @var array<string, string> */
	private const CATEGORY_ACTIONS = [
		'add' => 'add',
		'update' => 'update',
		'get' => 'get',
		'delete' => 'delete',
		'list' => 'list',
	];

	/** @var array<string, string> */
	private const STAGE_ACTIONS = [
		'add' => 'add',
		'update' => 'update',
		'get' => 'get',
		'delete' => 'delete',
		'list' => 'list',
		'replace' => 'replace',
	];

	/**
	 * @return array<string, array> The full name of a method => its route config.
	 */
	public function buildRoutes(): array
	{
		$routes = [];

		foreach (self::STATIC_ENTITY_TYPE_IDS as $entityTypeId)
		{
			$this->addRoutesOfEntityType($routes, EntityTypeSettings::of(EntityType::fromId($entityTypeId)));
		}

		foreach ($this->getSmartProcessSettings() as $settings)
		{
			$this->addRoutesOfEntityType($routes, $settings);
		}

		return $routes;
	}

	/**
	 * The smart processes of the portal, one settings object each. The identifiers are read out of the
	 * cached map of types in one pass and only then turned into settings: the map hands out an
	 * {@see \Bitrix\Main\ORM\Objectify\Collection}, which keeps one internal cursor, and reading a
	 * type while walking it - which {@see \Bitrix\Crm\Service\Container::getTypeByEntityTypeId()}
	 * does - would move that cursor.
	 *
	 * @return EntityTypeSettings[]
	 */
	protected function getSmartProcessSettings(): array
	{
		$entityTypeIds = [];
		foreach (Container::getInstance()->getDynamicTypesMap()->getTypesCollection() as $type)
		{
			$entityTypeId = (int)$type->getEntityTypeId();
			if (EntityType::isPossibleSmartProcessTypeId($entityTypeId))
			{
				$entityTypeIds[] = $entityTypeId;
			}
		}

		return array_map(
			static fn(int $entityTypeId): EntityTypeSettings
				=> EntityTypeSettings::of(EntityType::smartProcess($entityTypeId)),
			$entityTypeIds,
		);
	}

	/**
	 * The segment of the name of a method that says which entity type it belongs to. A smart process
	 * is spelled the way the domain spells its code, upper case letters and all - `SmartProcess1032`,
	 * never `smartprocess1032` - while Deal, Company and Contact keep the lower case every published
	 * `crm.<entity>.*` method of theirs already answers to. One source either way:
	 * {@see EntityType::getCode()}.
	 */
	private function getEntityTypeSegment(EntityType $entityType): string
	{
		$code = (string)$entityType->getCode();

		return $entityType->isSmartProcess() ? $code : mb_strtolower($code);
	}

	/**
	 * @param array<string, array> $routes
	 */
	private function addRoutesOfEntityType(array &$routes, EntityTypeSettings $settings): void
	{
		if (!$settings->hasCategories())
		{
			return;
		}

		$entityType = $settings->getEntityType();
		$prefix = 'crm.' . $this->getEntityTypeSegment($entityType) . '.category';
		$owner = $this->getOwnerName($entityType);

		foreach (self::CATEGORY_ACTIONS as $segment => $action)
		{
			$routes[$prefix . '.' . $segment] = [
				'controller' => Category::class,
				'method' => $action,
				'dtoGenerator' => CategoryDtoGenerator::class,
				'entityTypeId' => $entityType->getId(),
				'scopes' => self::SCOPES,
				'mcp' => $this->buildMcpMetadata($this->getCategoryDescription($segment, $owner)),
			];
		}

		if (!$settings->hasStages())
		{
			return;
		}

		foreach (self::STAGE_ACTIONS as $segment => $action)
		{
			$routes[$prefix . '.stage.' . $segment] = [
				'controller' => Stage::class,
				'method' => $action,
				'dtoFqcn' => StageDto::class,
				'entityTypeId' => $entityType->getId(),
				'scopes' => self::SCOPES,
				'mcp' => $this->buildMcpMetadata($this->getStageDescription($segment, $owner)),
			];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildMcpMetadata(string $descriptionOverride): array
	{
		return [
			'export' => true,
			'toolSetCode' => self::MCP_TOOL_SET_CODE,
			'descriptionOverride' => $descriptionOverride,
		];
	}

	private function getOwnerName(EntityType $entityType): string
	{
		return match ($entityType->getId())
		{
			OwnerType::DEAL => 'CRM deals',
			OwnerType::COMPANY => 'CRM companies',
			OwnerType::CONTACT => 'CRM contacts',
			default => 'CRM smart process type ' . $entityType->getId(),
		};
	}

	private function getCategoryDescription(string $segment, string $owner): string
	{
		return match ($segment)
		{
			'add' => 'Create a category for ' . $owner . '.',
			'update' => 'Update a category for ' . $owner . '.',
			'get' => 'Get a category for ' . $owner . ' by ID.',
			'delete' => 'Delete a category for ' . $owner . '.',
			'list' => 'List categories for ' . $owner . '.',
		};
	}

	private function getStageDescription(string $segment, string $owner): string
	{
		return match ($segment)
		{
			'add' => 'Create a stage in a category for ' . $owner . '.',
			'update' => 'Update a stage in a category for ' . $owner . '.',
			'get' => 'Get a stage in a category for ' . $owner . ' by ID.',
			'delete' => 'Delete a stage in a category for ' . $owner . '.',
			'list' => 'List stages in a category for ' . $owner . '.',
			'replace' => 'Replace the stages of a category for ' . $owner . '.',
		};
	}
}
