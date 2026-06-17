<?php

namespace Bitrix\Sign\Controllers\V1\B2e;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Result\Operation\Member\ValidateEntitySelectorMembersResult;
use Bitrix\Sign\Service\Sign\SignersList\AccessService;
use Bitrix\Sign\Service\SignersListService;
use Bitrix\Sign\Type\Hr\EntitySelector;
use Bitrix\Sign\Type\Member\EntityType;

class Signers extends Controller
{
	public function deleteListAction(
		int $listId,
		AccessService $accessService,
	): array
	{
		$list = $accessService->getAccessibleList($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_DELETE);
		if ($list === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$result = (new Operation\Signers\DeleteList($list))->launch();
		$this->addErrorsFromResult($result);

		return [];
	}

	public function deleteSignersFromListAction(
		int $listId,
		array $userIds,
		AccessService $accessService,
		SignersListService $signersListService,
	): array
	{
		$list = $accessService->getAccessibleList($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_EDIT);
		if ($list === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addErrorByMessage('Current user is not authorized');

			return [];
		}

		$result = $signersListService->deleteUsersFromList(
			$listId,
			$userIds,
			$currentUserId,
		);
		$this->addErrorsFromResult($result);

		return [];
	}

	public function addSignersToListAction(
		int $listId,
		array $members,
		AccessService $accessService,
		SignersListService $signersListService,
		bool $excludeRejected = true,
	): array
	{
		$list = $accessService->getAccessibleList($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_EDIT);
		if ($list === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$result = (new Operation\Member\Validation\ValidateEntitySelectorMembers($members))->launch();
		if (!$result instanceof ValidateEntitySelectorMembersResult)
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		$result = (new Operation\Member\GetMembersFromUserPartyEntities($result->entities, $excludeRejected))->launch();

		$userMembers = $result->members->filterByEntityTypes(EntityType::USER);
		$userIds = [];
		foreach ($userMembers as $user)
		{
			$userIds[$user->entityId] = true;
		}

		if ($result->departments->count() > 0)
		{
			if (!Loader::includeModule('humanresources'))
			{
				$this->addErrorByMessage('humanresources module is not installed');

				return [];
			}

			$nodeMemberService = \Bitrix\HumanResources\Service\Container::instance()->getNodeMemberService();

			foreach ($result->departments as $department)
			{
				$employees = $nodeMemberService->getAllEmployees(
					nodeId: $department->entityId,
					withAllChildNodes: $department->entityType !== EntitySelector\EntityType::FlatDepartment,
				);

				foreach ($employees->getIterator() as $employee)
				{
					$userIds[$employee->entityId] = true;
				}
			}
		}

		$userIds = array_keys($userIds);

		$currentUserId = (int)CurrentUser::get()->getId();

		if ($currentUserId < 1)
		{
			$this->addErrorByMessage('Current user is not authorized');

			return [];
		}

		$result = $signersListService->addUsersToList(
			$listId,
			$userIds,
			$currentUserId,
			ignoreDuplicates: true,
		);
		$this->addErrorsFromResult($result);
		return [];
	}

	public function copyListAction(
		int $listId,
		AccessService $accessService,
	): array
	{
		$list = $accessService->getAccessibleList($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_READ);
		if ($list === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		if (!$this->getAccessController()->check(ActionDictionary::ACTION_B2E_SIGNERS_LIST_ADD))
		{
			$this->addAccessDeniedError();

			return [];
		}

		$createdByUserId = (int)CurrentUser::get()->getId();

		if ($createdByUserId < 1)
		{
			$this->addErrorByMessage('Current user is not authorized');

			return [];
		}

		$result = (new Operation\Signers\CopyList($list, $createdByUserId))->launch();
		$this->addErrorsFromResult($result);

		return [];
	}

	#[ActionAccess(
		permission: ActionDictionary::ACTION_B2E_SIGNERS_LIST_ADD,
	)]
	public function createListAction(string $title, SignersListService $signersListService): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addErrorByMessage('Current user is not authorized');

			return [];
		}

		$result = $signersListService->createList(
			$title,
			$currentUserId,
		);
		$this->addErrorsFromResult($result);

		return [];
	}

	public function renameListAction(
		int $listId,
		string $title,
		AccessService $accessService,
		SignersListService $signersListService,
	): array
	{
		$list = $accessService->getAccessibleList($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_EDIT);
		if ($list === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addErrorByMessage('Current user is not authorized');

			return [];
		}

		$result = $signersListService->renameList(
			$listId,
			$title,
			$currentUserId,
		);
		$this->addErrorsFromResult($result);

		return [];
	}

	private function addAccessDeniedError(): void
	{
		\Bitrix\Main\Context::getCurrent()->getResponse()->setStatus(401);
		$this->addError(new \Bitrix\Main\Error(
			\Bitrix\Main\Localization\Loc::getMessage('MAIN_ENGINE_FILTER_AUTHENTICATION_ERROR'),
			'invalid_authentication',
		));
	}

}
