<?php

namespace Bitrix\Sign\Controllers\V1\Integration\Im;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute;
use Bitrix\Main;
use Bitrix\Intranet;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Sign\Operation\DocumentChat\CreateGroupChat;
use Bitrix\Sign\Result\Service\Integration\Im\CreateGroupChatResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\SignersList\AccessService;
use Bitrix\Sign\Service\SignersListService;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Type\Access\AccessibleItemType;
use Bitrix\Sign\Type\Document\EntityType;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Type\Integration\Im\DocumentChatType;

class GroupChat extends Controller
{
	private const FORM_CONTENT_TYPE = 'application/x-www-form-urlencoded';

	/**
	 * Returns default pre-filters for action.
	 * @return array
	 */
	protected function getDefaultPreFilters()
	{
		return
			[
				new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON, self::FORM_CONTENT_TYPE]),
				new Main\Engine\ActionFilter\Authentication(),
				new Main\Engine\ActionFilter\HttpMethod(
					[Main\Engine\ActionFilter\HttpMethod::METHOD_GET, Main\Engine\ActionFilter\HttpMethod::METHOD_POST]
				),
				new Intranet\ActionFilter\IntranetUser()
			];
	}

	public function configureActions(): array
	{
		$actions = parent::configureActions();
		$actions['createSignersListChat']['+prefilters'][] = new Main\Engine\ActionFilter\HttpMethod([
			Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
		]);
		$actions['createSignersListChat']['+prefilters'][] = new Main\Engine\ActionFilter\Csrf();

		return $actions;
	}


	#[Attribute\Access\LogicAnd(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentId',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_ADD,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentId',
		),
	)]
	public function createDocumentChatAction(int $chatType, int $documentId, DocumentRepository $documentRepository): array
	{
		$document = $documentRepository->getById($documentId);

		return $this->executeCreateChat($document, $chatType);
	}

	#[Attribute\Access\LogicAnd(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
	)]
	public function createDocumentChatByEntityAction(int $chatType, int $entityId, DocumentRepository $documentRepository): array
	{
		$document = $documentRepository->getByEntityIdAndType($entityId, EntityType::SMART_B2E);

		if ($document === null)
		{
			return $this->addWrongInputError();
		}

		$accessController = $this->getAccessController();
		if (
			!$accessController->checkByItem(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT, $document)
			|| !$accessController->checkByItem(ActionDictionary::ACTION_B2E_DOCUMENT_ADD, $document)
		)
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLERS_V1_INTEGRATION_IM_GROUP_CHAT_ERROR_ACCESS_DENIED'),
				'ACCESS_DENIED',
			));

			return [];
		}

		return $this->executeCreateChat($document, $chatType);
	}

	private function addWrongInputError(): array
	{
		$this->addError(new Error(Loc::getMessage('SIGN_INTEGRATION_ERROR_WRONG_INPUT')));

		return [];
	}

	private function executeCreateChat(?Document $document, int $chatType): array
	{
		$chatType = DocumentChatType::tryFrom($chatType);
		if ($chatType === null || $document === null)
		{
			return $this->addWrongInputError();
		}

		$createGroupChatResultOperation = new CreateGroupChat(
			$document,
			$chatType,
			CurrentUser::get()->getId()
		);
		$createGroupChatResult = $createGroupChatResultOperation->launch();
		if (!$createGroupChatResult instanceof CreateGroupChatResult)
		{
			$this->addErrorsFromResult($createGroupChatResult);

			return [];
		}

		return ['chatId' => $createGroupChatResult->chatId];
	}

	public function createSignersListChatAction(int $listId, AccessService $accessService, SignersListService $signersListService): array
	{
		$signersList = $accessService->getAccessibleList(
			$listId,
			ActionDictionary::ACTION_B2E_SIGNERS_LIST_READ
		);

		if ($signersList === null)
		{
			$this->addError(new Error('Access denied', 'ACCESS_DENIED'));

			return [];
		}

		if ($signersListService->isListEmpty($listId))
		{
			$this->addError(new Error('Cannot create chat for empty list'));
			return [];
		}

		$createGroupChatResultOperation = new \Bitrix\Sign\Operation\Signers\CreateGroupChat(
			$listId,
			CurrentUser::get()->getId(),
			$signersListService
		);
		$createGroupChatResult = $createGroupChatResultOperation->launch();
		if (!$createGroupChatResult instanceOf CreateGroupChatResult)
		{
			$this->addErrorsFromResult($createGroupChatResult);

			return [];
		}

		return [
			'chatId' => $createGroupChatResult->chatId,
			'warning' => $createGroupChatResult->warning,
		];
	}
}
