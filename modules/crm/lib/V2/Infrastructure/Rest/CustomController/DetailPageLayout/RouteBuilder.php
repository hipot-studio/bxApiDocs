<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DetailPageLayout;

use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\LayoutDto;
use Bitrix\Crm\V2\Public\OwnerType;

final class RouteBuilder
{
	public const FEATURE = RestV3CrudDeal::class;

	private const ENTITY_TYPES = [
		'lead' => OwnerType::LEAD,
		'deal' => OwnerType::DEAL,
		'contact' => OwnerType::CONTACT,
		'company' => OwnerType::COMPANY,
		'quote' => OwnerType::QUOTE,
		'smartInvoice' => OwnerType::SMART_INVOICE,
		'smartDocument' => OwnerType::SMART_DOCUMENT,
		'smartB2eDocument' => OwnerType::SMART_B2E_DOCUMENT,
	];

	private const ACTIONS = [
		'getCurrent' => 'getCurrentLayout',
		'setCurrent' => 'setCurrent',
		'getPersonalLayoutId' => 'getPersonalLayoutId',
		'getCommonLayoutId' => 'getCommonLayoutId',
		'setCommonForAll' => 'setCommonForAll',
		'add' => 'add',
		'update' => 'update',
		'delete' => 'delete',
		'get' => 'get',
		'list' => 'list',
	];

	private const MCP_DESCRIPTIONS = [
		'getCurrent' => 'Get the current detail page layout for %s.',
		'setCurrent' => 'Set the current detail page layout for %s.',
		'getPersonalLayoutId' => 'Get the personal detail page layout ID for %s.',
		'getCommonLayoutId' => 'Get the common detail page layout ID for %s.',
		'setCommonForAll' => 'Set the common detail page layout for all users of %s.',
		'add' => 'Create a detail page layout for %s.',
		'update' => 'Update a detail page layout for %s.',
		'delete' => 'Delete a detail page layout for %s.',
		'get' => 'Get a detail page layout for %s by ID.',
		'list' => 'List detail page layouts for %s.',
	];

	/** @return array<string, array> */
	public function buildRoutes(): array
	{
		$routes = [];
		foreach (self::ENTITY_TYPES as $segment => $entityTypeId)
		{
			$routes = array_merge($routes, $this->buildRoutesForEntity($segment, $entityTypeId));
		}

		return $routes;
	}

	/** @return array<string, array> */
	public function buildRoutesForEntity(string $segment, int $entityTypeId): array
	{
		$owner = match ($segment)
		{
			'lead' => 'CRM leads',
			'deal' => 'CRM deals',
			'contact' => 'CRM contacts',
			'company' => 'CRM companies',
			'quote' => 'CRM quotes',
			'smartInvoice' => 'CRM smart invoices',
			'smartDocument' => 'CRM smart documents',
			'smartB2eDocument' => 'CRM smart B2E documents',
			default => 'CRM smart process type ' . $entityTypeId,
		};
		$routes = [];
		foreach (self::ACTIONS as $action => $controllerMethod)
		{
			$mcp = [
				'export' => true,
				'toolSetCode' => 'crm_rest_v3_detail_page_layout',
				'descriptionOverride' => sprintf(self::MCP_DESCRIPTIONS[$action], $owner),
			];

			$routes['crm.' . $segment . '.detailPageLayout.' . $action] = [
				'controller' => DetailPageLayout::class,
				'method' => $controllerMethod,
				'dtoFqcn' => LayoutDto::class,
				'entityTypeId' => $entityTypeId,
				'scopes' => ['crm'],
				'mcp' => $mcp,
			];
		}

		return $routes;
	}
}
