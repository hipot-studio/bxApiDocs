<?php

namespace Bitrix\Sign\Service\Sign\SignersList;

use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\AccessController\AccessControllerFactory;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Service\SignersListService;

class AccessService
{
	private ?AccessController $accessController = null;

	public function __construct(
		private readonly SignersListService $signersListService,
		private readonly AccessControllerFactory $accessControllerFactory,
	)
	{
	}

	public function hasAccessToRead(int $listId): bool
	{
		return $this->check($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_READ);
	}

	public function hasAccessToEdit(int $listId): bool
	{
		return $this->check($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_EDIT);
	}

	public function hasAccessToDelete(int $listId): bool
	{
		return $this->check($listId, ActionDictionary::ACTION_B2E_SIGNERS_LIST_DELETE);
	}

	public function getAccessibleList(int $listId, string $action): ?\Bitrix\Sign\Item\SignersList
	{
		return $this->resolve($listId, $action, true);
	}

	private function check(int $listId, string $action): bool
	{
		return $this->resolve($listId, $action, false) === true;
	}

	/**
	 * @return \Bitrix\Sign\Item\SignersList|true|null
	 */
	private function resolve(int $listId, string $action, bool $shouldReturnItem): \Bitrix\Sign\Item\SignersList|true|null
	{
		if ($listId < 1)
		{
			return null;
		}

		$accessController = $this->getAccessController();
		if ($accessController === null)
		{
			return null;
		}

		if ($this->signersListService->isRejectedList($listId))
		{
			if (!$accessController->check(ActionDictionary::ACTION_B2E_SIGNERS_LIST_REFUSED_EDIT))
			{
				return null;
			}

			return $shouldReturnItem ? $this->signersListService->getById($listId) : true;
		}

		$list = $this->signersListService->getById($listId);
		if ($list === null)
		{
			return null;
		}

		if (!$accessController->checkByItem($action, $list))
		{
			return null;
		}

		return $shouldReturnItem ? $list : true;
	}

	private function getAccessController(): ?AccessController
	{
		$this->accessController ??= $this->accessControllerFactory->createByUserId(
			(int)\Bitrix\Main\Engine\CurrentUser::get()->getId(),
		);

		return $this->accessController;
	}
}
