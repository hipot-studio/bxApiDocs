<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Service\Container;

CBitrixComponent::includeComponentClass('bitrix:sign.base');

final class SignB2eSignersSignerEdit extends SignBaseComponent
{
	private \Bitrix\Sign\Service\SignersListService $signersListService;
	private \Bitrix\Sign\Service\Sign\SignersList\AccessService $accessService;

	private ?\Bitrix\Sign\Item\SignersList $list = null;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->signersListService = Container::instance()->getSignersListService();
		$this->accessService = Container::instance()->getSignersListAccessService();
	}

	public function executeComponent(): void
	{
		if (!\Bitrix\Sign\Config\Storage::instance()->isB2eAvailable())
		{
			$this->includeNotAvailableTemplate();

			return;
		}

		$listId = $this->getIntParam('LIST_ID');
		$this->list = $listId !== 0
			? $this->signersListService->getById($listId)
			: null
		;

		if ($listId === 0 || $this->list === null)
		{
			showError(Loc::getMessage('SIGN_B2E_SIGNERS_SIGNER_EDIT_ACCESS_DENIED'));

			return;
		}

		if (!$this->hasCurrentUserAccessToListForRead($listId))
		{
			showError(Loc::getMessage('SIGN_B2E_SIGNERS_SIGNER_EDIT_ACCESS_DENIED'));

			return;
		}

		parent::executeComponent();
	}

	public function exec(): void
	{
		$this->setResult('CAN_ADD_SIGNER', $this->canCurrentUserEditList($this->list));
		$this->setResult('CAN_DELETE_SIGNER', $this->canCurrentUserEditList($this->list));
		$this->setResult('LIST_ID', $this->list->id);
		$this->setResult('LIST_TITLE', $this->list->title);
	}

	private function hasCurrentUserAccessToListForRead(int $listId): bool
	{
		return $this->accessService->hasAccessToRead($listId);
	}

	private function canCurrentUserEditList(\Bitrix\Sign\Item\SignersList $list): bool
	{
		return $this->accessService->hasAccessToEdit($list->id);
	}
}
