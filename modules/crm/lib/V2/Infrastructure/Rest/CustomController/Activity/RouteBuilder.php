<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Activity;

use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * The routes of the activities read in the context of an entity type: `crm.<entity>.activity.<action>` for
 * Lead, Deal, Contact and Company and for every smart process of the portal, all served by
 * {@see EntityActivity}.
 *
 * Smart processes come and go with the portal, so the family is built from the actual composition of the
 * types, the way {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category\RouteBuilder} builds its
 * own. The walk reads the map of types that is already cached and makes no query per type.
 */
class RouteBuilder
{
	/**
	 * The feature the whole family is behind, named next to the routes so that the gate is keyed by the
	 * very names this builder returns: a route missing from the gate would stay open on every portal.
	 *
	 * @var class-string<\Bitrix\Crm\Feature\BaseFeature>
	 */
	public const FEATURE = RestV3CrudDeal::class;

	/**
	 * The item types with a static route; any smart process is served by a route of its own. The one list
	 * {@see EntityActivity} answers by too, so that no published route is refused by its controller.
	 *
	 * @var int[]
	 */
	public const STATIC_ENTITY_TYPE_IDS = [
		EntityType::LEAD,
		EntityType::DEAL,
		EntityType::CONTACT,
		EntityType::COMPANY,
	];

	/**
	 * The last segment of the name of a method => the action of {@see EntityActivity} serving it.
	 *
	 * @var array<string, string>
	 */
	private const ACTIONS = [
		'get' => 'get',
	];

	/**
	 * @return array<string, array> The full name of a method => its route config.
	 */
	public function buildRoutes(): array
	{
		$routes = [];

		foreach (self::STATIC_ENTITY_TYPE_IDS as $entityTypeId)
		{
			$this->addRoutesOfEntityType($routes, EntityType::fromId($entityTypeId));
		}

		foreach ($this->getSmartProcessEntityTypeIds() as $entityTypeId)
		{
			$this->addRoutesOfEntityType($routes, EntityType::smartProcess($entityTypeId));
		}

		return $routes;
	}

	/**
	 * The smart processes of the portal, filtered as the routes of their items are.
	 *
	 * @return int[]
	 */
	protected function getSmartProcessEntityTypeIds(): array
	{
		$entityTypeIds = [];
		foreach ($this->getDynamicEntityTypeIds() as $entityTypeId)
		{
			if (
				$entityTypeId !== null
				&& EntityType::isValid($entityTypeId)
				&& EntityType::isPossibleSmartProcessTypeId($entityTypeId)
			)
			{
				$entityTypeIds[] = $entityTypeId;
			}
		}

		return $entityTypeIds;
	}

	/**
	 * @return array<int|null> The types of the map of types as they are, not filtered yet.
	 */
	protected function getDynamicEntityTypeIds(): array
	{
		$entityTypeIds = [];
		foreach (Container::getInstance()->getDynamicTypesMap()->getTypesCollection() as $type)
		{
			$entityTypeId = $type->getEntityTypeId();
			$entityTypeIds[] = $entityTypeId === null ? null : (int)$entityTypeId;
		}

		return $entityTypeIds;
	}

	/**
	 * The segment of the name spelled as in the category family: the code of the type for a smart process,
	 * the lower case the other `crm.<entity>.*` methods are published under for the rest.
	 */
	private function getEntityTypeSegment(EntityType $entityType): string
	{
		$code = (string)$entityType->getCode();

		return $entityType->isSmartProcess() ? $code : mb_strtolower($code);
	}

	/**
	 * @param array<string, array> $routes
	 */
	private function addRoutesOfEntityType(array &$routes, EntityType $entityType): void
	{
		$entitySegment = $this->getEntityTypeSegment($entityType);
		$prefix = 'crm.' . $entitySegment . '.activity';
		$toolSetCode = $entityType->isSmartProcess()
			? 'crm_rest_v3_smart_process'
			: match ($entityType->getId())
			{
				EntityType::LEAD => 'crm_rest_v3_lead',
				EntityType::DEAL => 'crm_rest_v3_deal',
				EntityType::CONTACT => 'crm_rest_v3_contact',
				EntityType::COMPANY => 'crm_rest_v3_company',
			};

		foreach (self::ACTIONS as $segment => $action)
		{
			$routes[$prefix . '.' . $segment] = [
				'controller' => EntityActivity::class,
				'method' => $action,
				'dtoFqcn' => ActivityDto::class,
				'entityTypeId' => $entityType->getId(),
				'scopes' => EntityActivity::SCOPES,
				'mcp' => [
					'export' => true,
					'toolSetCode' => $toolSetCode,
					'descriptionOverride' => "Get an activity linked to CRM {$entitySegment} by identifier.",
				],
			];
		}
	}
}
