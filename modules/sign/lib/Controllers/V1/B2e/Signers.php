<?php

namespace Bitrix\Sign\Controllers\V1\B2e;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Result\Operation\Member\ValidateEntitySelectorMembersResult;
use Bitrix\Sign\Service\Integration\Socialnetwork\FeedPostService;
use Bitrix\Sign\Service\Sign\SignersList\AccessService;
use Bitrix\Sign\Service\Sign\SignersList\SignerEligibilityService;
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

		return [
			'hasSigners' => !$signersListService->isListEmpty($listId),
		];
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

		$validationResult = (new Operation\Member\Validation\ValidateEntitySelectorMembers($members))->launch();
		if (!$validationResult instanceof ValidateEntitySelectorMembersResult)
		{
			$this->addErrorsFromResult($validationResult);

			return [];
		}

		$result = (new Operation\Member\GetMembersFromUserPartyEntities($validationResult->entities, $excludeRejected))->launch();

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

		// Check the fully expanded set (including users pulled in via signers-list and
		// sign-document entities) so ineligible members cannot slip in through expansion.
		$eligibility = (new SignerEligibilityService())->classify($userIds);
		if ($eligibility->hasIneligibleUsers())
		{
			$this->addErrorByMessage(Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_SIGNERS_ERROR_INELIGIBLE_USERS'));

			return [];
		}

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
		return [
			'hasSigners' => !$signersListService->isListEmpty($listId),
		];
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
			Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_SIGNERS_ERROR_ACCESS_DENIED'),
			'invalid_authentication',
		));
	}


	public function pinListAction(
		int $listId,
		AccessService $accessService,
		SignersListService $signersListService,
	): ?array
	{
		return $this->changeListPinState($listId, true, $accessService, $signersListService);
	}

	public function unpinListAction(
		int $listId,
		AccessService $accessService,
		SignersListService $signersListService,
	): ?array
	{
		return $this->changeListPinState($listId, false, $accessService, $signersListService);
	}

	/**
	 * Recipients of a post in the activity stream for the group. Read access to the list is
	 * the right: its members are already visible to the user on this screen, and without that
	 * access the composition of the group must not be given away.
	 *
	 * Which employees of the group can receive a post, and whether the group fits one at all,
	 * is decided by the feed service; here the refusal only becomes an answer of the API.
	 */
	public function getFeedRecipientsAction(
		int $listId,
		AccessService $accessService,
		FeedPostService $feedPostService,
	): ?array
	{
		if (!$accessService->hasAccessToRead($listId))
		{
			$this->addAccessDeniedError();

			return null;
		}

		$recipients = $feedPostService->getRecipientCodesForList($listId);

		if ($recipients === null)
		{
			$this->addError(new Error(
				Loc::getMessage(
					'SIGN_CONTROLLERS_V1_B2E_SIGNERS_ERROR_FEED_RECIPIENTS_LIMIT',
					['#LIMIT#' => FeedPostService::MAX_RECIPIENT_COUNT],
				),
				'FEED_RECIPIENTS_LIMIT_EXCEEDED',
			));

			return null;
		}

		return ['recipients' => $recipients];
	}

	/**
	 * Pinning is a personal display setting, not a change of the group itself, so read
	 * access to the list is enough. The user is always taken from the session: accepting
	 * it from the request would let anyone rewrite another user's pins.
	 */
	private function changeListPinState(
		int $listId,
		bool $shouldBePinned,
		AccessService $accessService,
		SignersListService $signersListService,
	): ?array
	{
		if (!$accessService->hasAccessToRead($listId))
		{
			$this->addAccessDeniedError();

			return null;
		}

		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addErrorByMessage('Current user is not authorized');

			return null;
		}

		if ($shouldBePinned)
		{
			$result = $signersListService->pinList($listId, $currentUserId);
			if (!$result->isSuccess())
			{
				$this->addErrorsFromResult($result);

				return null;
			}
		}
		else
		{
			$signersListService->unpinList($listId, $currentUserId);
		}

		return ['pinned' => $shouldBePinned];
	}
}
