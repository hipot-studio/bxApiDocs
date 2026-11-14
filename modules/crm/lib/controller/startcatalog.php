<?php

declare(strict_types=1);

namespace Bitrix\Crm\Controller;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\SmartProcessStartForms;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\StartCatalog\CreateByForm;
use Bitrix\Crm\Service\StartCatalog\EntryContextValidator;
use Bitrix\Crm\Service\StartCatalog\FormCreator;
use Bitrix\Crm\Service\StartCatalog\FormResolver;
use Bitrix\Crm\Service\StartCatalog\TicketService;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\WebForm\Script;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * Opens and creates CRM forms of smart processes.
 */
final class StartCatalog extends Base
{
	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new Scope(Scope::NOT_REST);

		return $filters;
	}

	/**
	 * The entry context is checked and signed into the ticket passed to the form submission.
	 */
	public function getFormEmbedAction(int $entityTypeId, array $context = []): ?array
	{
		if (!$this->isFeatureEnabled())
		{
			return null;
		}

		$userPermissions = $this->getUserPermissions();
		if (!$userPermissions->entityType()->canAddItems($entityTypeId))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$addRestriction = RestrictionManager::getAddOperationRestriction($entityTypeId);
		if (!$addRestriction->hasPermission())
		{
			$message = (string)$addRestriction->getErrorMessage();
			$this->addError(
				$message === ''
					? ErrorCode::getAccessDeniedError()
					: new Error($message, (string)$addRestriction->getErrorCode()),
			);

			return null;
		}

		$form = (new FormResolver())->getBoundForm($entityTypeId);
		if ($form === null)
		{
			$this->addError(
				new Error(
					Loc::getMessage('CRM_CONTROLLER_START_CATALOG_FORM_IS_GONE'),
					ErrorCode::NOT_FOUND,
					['url' => $this->getSectionUrl($entityTypeId)],
				),
			);

			return null;
		}

		// a right to add items in another funnel is not a right to the item the form creates in its own
		if (!CreateByForm::isFormPermitted($entityTypeId, $form, $userPermissions))
		{
			$accessDenied = ErrorCode::getAccessDeniedError();
			$this->addError(
				new Error(
					$accessDenied->getMessage(),
					$accessDenied->getCode(),
					['url' => $this->getSectionUrl($entityTypeId)],
				),
			);

			return null;
		}

		$scriptContext = Script::getListContext(
			[
				'ID' => $form['id'],
				'CODE' => $form['code'],
				'SECURITY_CODE' => $form['securityCode'],
			],
			[],
		);

		$ticket = (new TicketService())->issue(
			$this->getCurrentUserId(),
			$entityTypeId,
			$form['id'],
			(new EntryContextValidator())->sanitize($entityTypeId, $context, $userPermissions),
		);

		return [
			'html' => (string)($scriptContext['INLINE']['text'] ?? ''),
			'ticket' => $ticket,
			'formId' => $form['id'],
		];
	}

	/**
	 * Retains the old action without looking up or changing a submitted item.
	 */
	public function resolveSubmittedItemAction(string $ticket, int $resultId): ?array
	{
		if (!$this->isFeatureEnabled())
		{
			return null;
		}

		$this->addError(ErrorCode::getNotFoundError());

		return null;
	}

	public function createFormAction(int $entityTypeId): ?array
	{
		if (!$this->isFeatureEnabled())
		{
			return null;
		}

		$userPermissions = $this->getUserPermissions();
		if (
			!$userPermissions->dynamicType()->canUpdate($entityTypeId)
			|| !$userPermissions->webForm()->canEdit()
		)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$result = (new FormCreator())->create($entityTypeId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * Asked by the form list before deleting forms: which smart processes would lose their form. A form
	 * counts as bound whatever became of it - a switched off one is still chosen in the settings. The
	 * titles are told to whoever may delete the forms, without asking whether they may read the types.
	 */
	public function getFormBindingsAction(array $formIds): ?array
	{
		if (!$this->isFeatureEnabled())
		{
			return null;
		}

		if (!$this->getUserPermissions()->webForm()->canEdit())
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$wanted = array_fill_keys(
			array_filter(array_map('intval', $formIds), static fn(int $formId): bool => $formId > 0),
			true,
		);

		$typesByForm = [];
		foreach (Container::getInstance()->getDynamicTypesMap()->getTypesCollection()->getAll() as $type)
		{
			$formId = (int)$type->getStartCatalogFormId();
			if (isset($wanted[$formId]))
			{
				$typesByForm[$formId][] = [
					'entityTypeId' => $type->getEntityTypeId(),
					'title' => (string)$type->getTitle(),
				];
			}
		}

		$bindings = [];
		foreach (array_keys($wanted) as $formId)
		{
			if (isset($typesByForm[$formId]))
			{
				$bindings[] = [
					'formId' => $formId,
					'types' => $typesByForm[$formId],
				];
			}
		}

		return [
			'bindings' => $bindings,
		];
	}

	private function isFeatureEnabled(): bool
	{
		if (!Feature::enabled(SmartProcessStartForms::class))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return false;
		}

		return true;
	}

	private function getUserPermissions(): UserPermissions
	{
		return Container::getInstance()->getUserPermissions($this->getCurrentUserId());
	}

	private function getCurrentUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}

	private function getSectionUrl(int $entityTypeId): string
	{
		return (string)Container::getInstance()->getRouter()->getItemListUrlInCurrentView($entityTypeId);
	}
}
