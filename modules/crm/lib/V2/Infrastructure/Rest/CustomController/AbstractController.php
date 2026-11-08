<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController;

use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Internal\Service\Rest\CategoryOwnerAccess;
use Bitrix\Crm\V2\Internal\Service\Rest\CategoryOwnerAccessException;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\FeatureNotAvailableOnCurrentPlanException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;

abstract class AbstractController extends RestController
{
	protected int $userId;

	protected function init(): void
	{
		parent::init();

		$this->syncUserId();
	}

	protected function processBeforeAction(Action $action): bool
	{
		// The user is injected after init(), so resolve it after the action is known.
		$this->syncUserId();

		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		$sourceParameters = $this->getSourceParametersList()[0] ?? [];
		// only SchemaProvider sets entityTypeId as int (trusted value), a string comes from the client query
		if (is_int($sourceParameters['entityTypeId'] ?? null))
		{
			$this->checkEntityAvailability($this->getEntityType());
		}

		return true;
	}

	protected function checkEntityAvailability(EntityType $entityType): void
	{
		if ($entityType->getId() !== OwnerType::LEAD)
		{
			return;
		}

		$this->checkLeadAvailability();
	}

	protected function checkLeadAvailability(): void
	{
		if (!RestrictionManager::getLeadsRestriction()->hasPermission())
		{
			throw new FeatureNotAvailableOnCurrentPlanException();
		}

		if (!LeadSettings::isEnabled())
		{
			throw new AccessDeniedException();
		}
	}

	public function getAutoWiredParameters(): array
	{
		return array_merge(
			parent::getAutoWiredParameters(),
			[
				new Parameter(
					EntityType::class,
					fn (): EntityType => $this->getEntityType(),
				),
			],
		);
	}

	protected function getEntityType(string $paramName = 'entityTypeId'): EntityType
	{
		$sourceEntityTypeId = $this->getSourceParametersList()[0][$paramName] ?? null;
		// if sourceEntityTypeId is int, it is set explicitly in SchemaProvider (trusted value)
		// if sourceEntityTypeId is not set in SchemaProvider, it is filled from Request Query params
		// if sourceEntityTypeId is filled from Request Query params, it has type "string"
		if (is_int($sourceEntityTypeId))
		{
			return EntityType::fromId($sourceEntityTypeId);
		}

		$bodyEntityTypeId = (int)$this->getRequest()->getJsonList()->getRaw($paramName);
		if (empty($bodyEntityTypeId))
		{
			throw new RequiredFieldInRequestException($paramName);
		}

		try
		{
			return EntityType::smartProcess($bodyEntityTypeId);
		}
		catch (ArgumentException)
		{
			throw new EntityNotFoundException($bodyEntityTypeId);
		}
	}

	protected function getRouteCategoryId(): ?int
	{
		$categoryId = $this->getSourceParametersList()[0]['categoryId'] ?? null;

		return is_int($categoryId) ? $categoryId : null;
	}

	protected function assertCategoryOwnerAllowed(ItemId $owner): void
	{
		$categoryId = $this->getRouteCategoryId();
		if ($categoryId === null)
		{
			return;
		}

		try
		{
			(new CategoryOwnerAccess())->assertAllowed($owner, $categoryId, $this->userId);
		}
		catch (CategoryOwnerAccessException)
		{
			throw new EntityNotFoundException($owner->getId());
		}
	}

	protected function writeToLogException(\Throwable $e): void
	{
		if ($e instanceof EntityNotFoundException)
		{
			return;
		}

		parent::writeToLogException($e);
	}

	private function syncUserId(): void
	{
		$injectedUserId = (int)($this->getCurrentUser()?->getId() ?? 0);

		$this->userId = $injectedUserId > 0 ? $injectedUserId : (int)CurrentUser::get()->getId();
	}
}
