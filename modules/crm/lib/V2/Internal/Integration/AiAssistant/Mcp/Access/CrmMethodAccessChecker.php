<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;
use Bitrix\Crm\V2\Infrastructure\Rest\Controller\Lead;
use Bitrix\Crm\V2\Infrastructure\Rest\Controller\MyCompany\Basic;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Form as CustomForm;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Product;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking;
use Bitrix\Crm\V2\Internal\Exception\WebForm\OpenLineAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\WebForm\OpenLinesNotInstalledException;
use Bitrix\Crm\V2\Internal\Integration\ImOpenLines\OpenLineGateway;
use Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess;
use Bitrix\Crm\V2\Internal\Service\Dictionary\DictionaryAccess;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Mcp\Access\MethodAccessCheckerInterface;
use Bitrix\Rest\V3\Schema\MethodDescription;

Loader::requireModule('rest');

final class CrmMethodAccessChecker implements MethodAccessCheckerInterface
{
	private const ROUTE_ENTITY_TYPE_PARAM = 'entityTypeId';

	private const OWNER_DEFAULT_CATEGORY_ID = 0;

	public function __construct(
		private readonly ?CategoryAccess $categoryAccess = null,
		private readonly ?OpenLineGateway $openLineGateway = null,
	)
	{
	}

	public function canAccess(MethodDescription $methodDescription, int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return match ($methodDescription->controllerFqcn)
		{
			Item\MyCompany::class => $this->canAccessMyCompany($methodDescription, $userId),
			Item\Item::class => $this->canAccessItem($methodDescription, $userId),
			Basic::class => $this->canReadMyCompanyBaseFields($userId),
			Field\Field::class, Item\ProductRow\Field::class => $this->canReadItemsOfRoute($methodDescription, $userId),
			Category\Category::class => $this->canAccessCategory($methodDescription, $userId),
			// Category-specific access is checked by the controller once the category ID is known.
			Category\Stage::class => true,
			Form\Form::class => $this->canAccessForm($methodDescription, $userId),
			CustomForm\Configuration::class,
			CustomForm\Field::class,
			CustomForm\Scenario::class,
			CustomForm\ScenarioCategory::class,
			CustomForm\EntityField::class => $this->canReadForms($userId),
			CustomForm\Embedding::class => $this->canAccessFormEmbedding($methodDescription, $userId),
			CustomForm\SiteButton::class => $this->canReadForms($userId) && $this->canReadSiteButtons($userId),
			CustomForm\OpenLine::class => $this->canReadForms($userId) && $this->canModifyOpenLineChats($userId),
			Lead::class => Container::getInstance()->getUserPermissions($userId)->isCrmAdmin(),
			Product\Product::class,
			Requisite\Requisite::class => Container::getInstance()->getUserPermissions($userId)->isAdmin(),
			Requisite\Preset::class => $this->canReadItemsOfRouteInDefaultCategory($methodDescription, $userId),
			Dictionary\DictionaryValue::class => $this->canAccessDictionaryValue($methodDescription, $userId),
			// Trace-level access to the bound items is checked by the controller.
			Tracking\Trace::class => $this->canReadSomeItemsInCrm($userId),
			default => true,
		};
	}

	private function canAccessItem(MethodDescription $methodDescription, int $userId): bool
	{
		$entityTypeId = $this->getRouteEntityTypeId($methodDescription);
		if ($entityTypeId === null)
		{
			return false;
		}

		$permissions = Container::getInstance()->getUserPermissions($userId)->entityType();

		return match ($methodDescription->method)
		{
			'get', 'list', 'tail', 'aggregate' => $permissions->canReadItems($entityTypeId),
			'add' => $permissions->canAddItems($entityTypeId),
			'update', 'addFieldValue', 'deleteFieldValue' => $permissions->canUpdateItems($entityTypeId),
			'delete' => $permissions->canDeleteItems($entityTypeId),
			default => false,
		};
	}

	private function canAccessMyCompany(MethodDescription $methodDescription, int $userId): bool
	{
		$permissions = Container::getInstance()->getUserPermissions($userId)->myCompany();

		return match ($methodDescription->method)
		{
			'get', 'list', 'aggregate' => $permissions->canRead(),
			'add' => $permissions->canAdd(),
			'update', 'addFieldValue', 'deleteFieldValue' => $permissions->canUpdate(),
			'delete' => $permissions->canDelete(),
			default => false,
		};
	}

	private function canReadMyCompanyBaseFields(int $userId): bool
	{
		return Container::getInstance()->getUserPermissions($userId)->myCompany()->canReadBaseFields();
	}

	private function canReadItemsOfRoute(MethodDescription $methodDescription, int $userId): bool
	{
		$entityTypeId = $this->getRouteEntityTypeId($methodDescription);
		if ($entityTypeId === null)
		{
			return false;
		}

		return Container::getInstance()
			->getUserPermissions($userId)
			->entityType()
			->canReadItems($entityTypeId)
		;
	}

	private function canAccessCategory(MethodDescription $methodDescription, int $userId): bool
	{
		if ($methodDescription->method !== 'add')
		{
			return true;
		}

		$entityTypeId = $this->getRouteEntityTypeId($methodDescription);
		if ($entityTypeId === null)
		{
			return false;
		}

		return $this->getCategoryAccess()->canWriteCategory($entityTypeId, $userId, null);
	}

	private function canAccessForm(MethodDescription $methodDescription, int $userId): bool
	{
		$permissions = Container::getInstance()->getUserPermissions($userId)->webForm();

		return match ($methodDescription->method)
		{
			'get', 'list' => $permissions->canRead(),
			'add', 'update', 'delete', 'validate', 'activate', 'deactivate' => $permissions->canEdit(),
			default => true,
		};
	}

	private function canAccessFormEmbedding(MethodDescription $methodDescription, int $userId): bool
	{
		$permissions = Container::getInstance()->getUserPermissions($userId)->webForm();

		return match ($methodDescription->method)
		{
			'update' => $permissions->canEdit(),
			default => $permissions->canRead(),
		};
	}

	private function canReadForms(int $userId): bool
	{
		return Container::getInstance()->getUserPermissions($userId)->webForm()->canRead();
	}

	private function canReadSiteButtons(int $userId): bool
	{
		return Container::getInstance()->getUserPermissions($userId)->siteButton()->canRead();
	}

	private function canModifyOpenLineChats(int $userId): bool
	{
		try
		{
			$this->getOpenLineGateway()->assertCanModifyChats($userId);
		}
		catch (OpenLinesNotInstalledException|OpenLineAccessDeniedException)
		{
			return false;
		}

		return true;
	}

	private function canReadSomeItemsInCrm(int $userId): bool
	{
		return Container::getInstance()->getUserPermissions($userId)->entityType()->canReadSomeItemsInCrm();
	}

	private function canReadItemsOfRouteInDefaultCategory(MethodDescription $methodDescription, int $userId): bool
	{
		$entityTypeId = $this->getRouteEntityTypeId($methodDescription);
		if ($entityTypeId === null)
		{
			return false;
		}

		return Container::getInstance()
			->getUserPermissions($userId)
			->entityType()
			->canReadItemsInCategory($entityTypeId, self::OWNER_DEFAULT_CATEGORY_ID)
		;
	}

	private function canAccessDictionaryValue(MethodDescription $methodDescription, int $userId): bool
	{
		$access = new DictionaryAccess();

		return match ($methodDescription->method)
		{
			'get', 'list' => $access->canRead($userId),
			'add', 'update', 'delete' => $access->canWrite($userId),
			default => true,
		};
	}

	private function getRouteEntityTypeId(MethodDescription $methodDescription): ?int
	{
		$value = $methodDescription->queryParams[self::ROUTE_ENTITY_TYPE_PARAM] ?? null;
		if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1)
		{
			$value = (int)$value;
		}

		return is_int($value) && $value > 0 ? $value : null;
	}

	private function getCategoryAccess(): CategoryAccess
	{
		return $this->categoryAccess ?? new CategoryAccess();
	}

	private function getOpenLineGateway(): OpenLineGateway
	{
		return $this->openLineGateway ?? new OpenLineGateway();
	}
}
