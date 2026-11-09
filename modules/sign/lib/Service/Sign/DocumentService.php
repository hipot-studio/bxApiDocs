<?php

namespace Bitrix\Sign\Service\Sign;

use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Access\Permission\PermissionDictionary;
use Bitrix\Sign\Document;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Sign\Internal\DocumentTable;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Document\BindingCollection;
use Bitrix\Sign\Item\Document\Template\TemplateFolderRelation;
use Bitrix\Sign\Main\User;
use Bitrix\Sign\Operation\ChangeDocumentStatus;
use Bitrix\Sign\Operation\CheckDocumentAccess;
use Bitrix\Sign\Operation\ConfigureFillAndStart;
use Bitrix\Sign\Operation\Document\RestoreState;
use Bitrix\Sign\Operation\Document\Validation;
use Bitrix\Sign\Operation\Document\Blank\Delete;
use Bitrix\Sign\Operation\Kanban\B2e\SendDeleteEntityPullEvent;
use Bitrix\Sign\Operation\Result\ConfigureResult;
use Bitrix\Sign\Repository\BlankRepository;
use Bitrix\Sign\Repository\Document\TemplateFolderRelationRepository;
use Bitrix\Sign\Repository\BlockRepository;
use Bitrix\Sign\Repository\Document\TemplateRepository;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\Service\Sign\Template\CreateTemplateFolderRelationResult;
use Bitrix\Sign\Result\CreateDocumentResult;
use Bitrix\Sign\Result\Service\Sign\Document\CreateTemplateResult;
use Bitrix\Sign\Service;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type;
use Bitrix\Sign\Type\Document\CreationSourceType;
use Bitrix\Sign\Type\Document\EntityType;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\ProviderCode;
use Bitrix\Sign\Type\Template;
use Bitrix\Sign\Type\Template\Visibility;
use Bitrix\Sign\Type\Document\Version;

class DocumentService
{
	/**
	 * The local write of a change failed. Whether the change stays applied anyway is not told by
	 * the code: a change the signing service has taken is marked on the result itself.
	 *
	 * @see \Bitrix\Sign\Item\Document\Configure\Step::markExternalApplied()
	 */
	public const ERROR_DOCUMENT_SAVE_FAILED = 'DOCUMENT_SAVE_FAILED';

	private const DOCUMENT_NAME_LENGTH_LIMIT = 100;
	public const ERROR_CODE_DOCUMENT_IN_FINAL_STATUS = 'DOCUMENT_IN_FINAL_STATUS';
	public const ERROR_CODE_DOCUMENT_NOT_INITIATED_BY_COMPANY = 'DOCUMENT_NOT_INITIATED_BY_COMPANY';
	public const ERROR_CODE_DOCUMENT_NOT_B2E = 'DOCUMENT_NOT_B2E';

	private DocumentRepository $documentRepository;
	private BlankRepository $blankRepository;
	private BlankService $blankService;
	private Service\Integration\Crm\EventHandlerService $eventHandlerService;
	private Document\Entity\Factory $documentEntityFactory;
	private readonly Service\Sign\Document\ProviderCodeService $providerCodeService;
	private readonly TemplateRepository $documentTemplateRepository;
	private readonly Service\Sign\Document\TemplateService $documentTemplateService;
	private readonly Service\Sign\Document\SignUntilService $signUntilService;
	private readonly MemberRepository $memberRepository;
	private readonly TemplateFolderRelationRepository $templateFolderRelationRepository;
	private readonly BlockRepository $blockRepository;
	private readonly Service\Integration\HumanResources\StructureNodeService $structureNodeService;
	private readonly Service\Integration\Disk\DiskService $diskService;

	public function __construct(
		?DocumentRepository $documentRepository = null,
		?BlankService $blankService = null,
		?BlankRepository $blankRepository = null,
		?Service\Integration\Crm\EventHandlerService $eventHandlerService = null,
		private bool $checkPermission = true,
		?Service\Sign\Document\ProviderCodeService $providerCodeService = null,
		?TemplateRepository $documentTemplateRepository = null,
		?Service\Sign\Document\TemplateService $documentTemplateService = null,
		?Service\Sign\Document\SignUntilService $signUntilService = null,
		?MemberRepository $memberRepository = null,
		?TemplateFolderRelationRepository $templateFolderRelationRepository = null,
		?Service\Integration\Disk\DiskService $diskService = null,
		?Document\Entity\Factory $documentEntityFactory = null,
	)
	{
		$container = Container::instance();

		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->blankService = $blankService ?? $container->getSignBlankService();
		$this->blankRepository = $blankRepository ?? $container->getBlankRepository();
		$this->eventHandlerService = $eventHandlerService ?? $container->getEventHandlerService();
		$this->documentEntityFactory = $documentEntityFactory ?? new Document\Entity\Factory();
		$this->providerCodeService = $providerCodeService ?? $container->getProviderCodeService();
		$this->documentTemplateRepository = $documentTemplateRepository ?? $container->getDocumentTemplateRepository();
		$this->documentTemplateService = $documentTemplateService ?? $container->getDocumentTemplateService();
		$this->signUntilService = $signUntilService ?? $container->getSignUntilService();
		$this->memberRepository = $memberRepository ?? $container->getMemberRepository();
		$this->templateFolderRelationRepository = $templateFolderRelationRepository ?? $container->getTemplateFolderRelationRepository();
		$this->blockRepository = $container->getBlockRepository();
		$this->structureNodeService = Service\Container::instance()->getHumanResourcesStructureNodeService();
		$this->diskService = $diskService ?? $container->getDiskService();
	}

	/**
	 * @param bool $checkPermission
	 *
	 * @return \Bitrix\Sign\Service\Sign\DocumentService
	 */
	public function setCheckPermission(bool $checkPermission): static
	{
		$this->checkPermission = $checkPermission;

		return $this;
	}

	/**
	 * @param int $blankId
	 * @param string $title
	 * @param bool|null $checkPermission null keeps the permission check configured for the service
	 *
	 * @return \Bitrix\Main\Result
	 */
	public function register(
		int $blankId,
		int $createdById,
		string $title = '',
		?int $entityId = null,
		?string $entityType = null,
		bool $asTemplate = false,
		InitiatedByType $initiatedByType = InitiatedByType::COMPANY,
		int $chatId = 0,
		?int $templateId = null,
		?BindingCollection $bindings = null,
		?Item\Document\CreationSource $creationSource = null,
		?bool $checkPermission = null,
	): Main\Result
	{
		$result = new Main\Result();
		if ($createdById < 1)
		{
			return $result->addError(new Main\Error(Loc::getMessage('SIGN_SERVICE_DOCUMENT_USER_NOT_FOUND')));
		}

		try
		{
			$blank = $this->blankRepository->getById($blankId);
		}
		catch (Main\ObjectPropertyException|Main\ArgumentException|Main\SystemException $e)
		{
			$blank = null;
		}

		if (!$blank)
		{
			return $result->addError(new Main\Error(Loc::getMessage('SIGN_SERVICE_DOCUMENT_BLANK_NOT_FOUND')));
		}

		if (
			$blank->scenario === Type\BlankScenario::B2B && $entityType === Type\Document\EntityType::SMART_B2E
			|| $blank->scenario === Type\BlankScenario::B2E && $entityType === Type\Document\EntityType::SMART
		)
		{
			return (new Main\Result())->addError(new Main\Error('Wrong blank scenario for current document'));
		}

		$documentItem = new Item\Document(entityType: $entityType, entityId: $entityId, templateId: $templateId);
		$documentItem->initiatedByType = $initiatedByType;
		$this->applyCreationSource($documentItem, $creationSource);

		$documentItem->dateSignUntil = $blank->scenario === Type\BlankScenario::B2B
			? null
			: $this->signUntilService->calcDefaultSignUntilDate(new DateTime())
		;

		if ($blank->scenario === Type\BlankScenario::B2B && $chatId)
		{
			$chatService = Service\Container::instance()->getImService();
			$chat = $chatService->getCollabById($chatId);

			if ($chat && $chatService->isUserHaveAccessToChat($chat, $createdById))
			{
				$documentItem->chatId = $chatId;
			}
		}

		$template = null;
		if ($result->isSuccess() && $asTemplate)
		{
			$createTemplateResult = $this->makeTemplateForDocument(
				$documentItem,
				$title,
				Main\Engine\CurrentUser::get()->getId(),
			);

			if (!$createTemplateResult instanceof CreateTemplateResult)
			{
				return $createTemplateResult;
			}

			$createTemplateFolderRelationResult = $this->makeTemplateFolderRelation($createTemplateResult->template);
			if (!$createTemplateFolderRelationResult instanceof CreateTemplateFolderRelationResult)
			{
				return $createTemplateFolderRelationResult;
			}

			$template = $createTemplateResult->template;
		}

		$result = $this->insertToDB($title, $blank, $documentItem, $createdById, $bindings, $checkPermission);

		if (!$result->isSuccess())
		{
			return $result;
		}

		$apiDocument = Service\Container::instance()
			->getApiDocumentService();

		$documentRegisterRequest = new Item\Api\Document\RegisterRequest(
			lang: $documentItem->langId,
			scenario: $documentItem->scenario,
			title: $documentItem->title,
			version: $documentItem->version,
			hasPlaceholders: $blank->hasPlaceholders,
			creationSourceType: ($documentItem->creationSourceType ?? CreationSourceType::UNKNOWN)->value,
			// Read here and nowhere else: copying a template registers a carrier with asTemplate
			// false, and Operation\Document\Copy clears templateId right after this call.
			isTemplate: $documentItem->isTemplated(),
		);
		$documentRegisterResponse = $apiDocument->register($documentRegisterRequest);

		if (!$documentRegisterResponse->isSuccess())
		{
			return $result
				->addErrors($documentRegisterResponse->getErrors())
				->setData(['documentId' => $documentItem->id])
			;
		}

		$documentItem->uid = $documentRegisterResponse->uid;

		$result = $this->documentRepository->update($documentItem);

		return $result->setData([...$result->getData(),
			'template' => $template,
			'documentId' => $documentItem->id,
		]);
	}

	private function applyCreationSource(Item\Document $documentItem, ?Item\Document\CreationSource $source): void
	{
		if ($source === null)
		{
			return;
		}

		$documentItem->creationSourceType = $source->type;
		$documentItem->creationSourceId = $source->id;
		$documentItem->creationSourceEntityTypeId = $source->entityTypeId;
		$documentItem->creationSourceEntityId = $source->entityId;
		$documentItem->creationSourceSpecifics = $source->specifics;
	}

	/**
	 * @param string $uid
	 * @param string $title
	 *
	 * @return \Bitrix\Main\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function modifyTitle(string $uid, string $title): Main\Result
	{
		$result = new Main\Result;
		if ($title === '')
		{
			return $result->addError(new Main\Error('Title is empty'));
		}

		$document = $this->documentRepository->getByUid($uid);
		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$document->title = $title;
		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return $result->addError(new Main\Error('Error when trying to save document'));
		}

		if (
			Type\DocumentScenario::isB2EScenario($document->scenario)
			&& $this->documentRepository->getCountByBlankId($document->blankId) === 1
		)
		{
			$this->blankService->changeBlankTitleByDocument($document, $document->title);
			$result->setData(['blankTitle' => $document->title]);
		}
		if (
			Type\DocumentScenario::isB2EScenario($document->scenario)
			&& $document->isTemplated()
		)
		{
			$updateResult = $this->documentTemplateService->updateTitle($document->templateId, $title);
			if (!$updateResult->isSuccess())
			{
				return $updateResult;
			}
		}

		$this->refreshSmartDocumentTitle($document);

		return $result;
	}

	private function composeSmartDocumentTitle(
		Item\Document $item,
		?\Bitrix\Sign\Document\Entity\Dummy $smartDocument,
	): string
	{
		if (!Type\DocumentScenario::isB2EScenario($item->scenario))
		{
			return (string)$item->title;
		}

		$number =
			Application::getInstance()->getLicense()->getRegion() !== 'ru'
			&& (string)$smartDocument?->getNumber() !== ''
				? $smartDocument->getNumber()
				: $item->externalId
		;

		return match (Application::getInstance()->getLicense()->getRegion())
		{
			'ru' => trim($number ? Loc::getMessage('SIGN_SERVICE_DOCUMENT_TITLE_FORMAT', [
				'#TITLE#' => $item->title,
				'#NUM#' => $number,
			]) : $item->title) ,
			default => trim($item->title),
		};
	}

	/**
	 * Recomposes the title of the linked smart process card.
	 * The card title is derived from the document title and the external id, but is not updated
	 * together with them. A card that refuses the write is worth an error: for a caller recomposing
	 * the title of its own accord, that error is the only sign the card kept the title it had. A
	 * document with no card in crm behind it has no title to recompose and reports nothing, and so
	 * does a card already carrying the composed title: saving a crm item runs every field of it.
	 *
	 * @see self::readMyCompanyId() reads the card of the document the same way
	 */
	public function refreshSmartDocumentTitle(Item\Document $document): Main\Result
	{
		$result = new Main\Result();
		$smartDocument = $this->documentEntityFactory->getByDocument($document);
		if ($smartDocument === null || $smartDocument->getId() < 1)
		{
			return $result;
		}

		$title = $this->composeSmartDocumentTitle($document, $smartDocument);
		if ($title === $smartDocument->getTitle())
		{
			return $result;
		}

		return $smartDocument->setTitle($title)
			? $result
			: $result->addError(new Main\Error('Error when trying to save smart document title'))
		;
	}

	public function getComposedTitleByDocument(Item\Document $item): string
	{
		if (!Type\DocumentScenario::isB2EScenario($item->scenario) || $item->externalId === null)
		{
			return (string)$item->title;
		}

		return match (Application::getInstance()->getLicense()->getRegion())
		{
			'ru' => trim(Loc::getMessage('SIGN_SERVICE_DOCUMENT_TITLE_FORMAT', [
				'#TITLE#' => $item->title,
				'#NUM#' => $item->externalId,
			])),
			default => (string)$item->title,
		};
	}

	public function getTitleWithAutoNumber(Item\Document $item): string
	{
		if (!Type\DocumentScenario::isB2EScenario($item->scenario))
		{
			return (string)$item->title;
		}

		$number = (string)$item->externalId;
		if (Application::getInstance()->getLicense()->getRegion() !== 'ru')
		{
			$smartDocument = $this->documentEntityFactory->getByDocument($item);
			$smartDocumentNumber = (string)$smartDocument?->getNumber();
			if ($smartDocumentNumber !== '')
			{
				$number = "#$smartDocumentNumber";
			}
		}

		return trim("$item->title $number");
	}

	/**
	 * @param string $uid
	 * @param string $title
	 *
	 * @return \Bitrix\Main\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function modifyLangId(string $uid, string $langId): Main\Result
	{
		$result = new Main\Result;
		if ($langId === '')
		{
			return $result->addError(new Main\Error('Lang id is empty'));
		}

		$document = $this->documentRepository->getByUid($uid);
		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$document->langId = $langId;
		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return $result->addError(new Main\Error('Error when trying to save document'));
		}

		return $result;
	}

	/**
	 * @param string $uid
	 * @param string $initiator
	 *
	 * @return \Bitrix\Main\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function modifyInitiator(string $uid, string $initiator): Main\Result
	{
		$result = new Main\Result;
		if ($initiator === '')
		{
			return $result->addError(new Main\Error('Initiator is empty'));
		}

		$document = $this->documentRepository->getByUid($uid);
		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$document->initiator = $initiator;
		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return $result->addError(new Main\Error('Error when trying to save document'));
		}

		return $result;
	}

	/**
	 * @param string $uid
	 * @param InitiatedByType $initiatedByType
	 */
	public function modifyInitiatedByType(string $uid, InitiatedByType $initiatedByType): Main\Result|CreateDocumentResult
	{
		$result = new Main\Result();

		$document = $this->documentRepository->getByUid($uid);

		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}
		if ($document->initiatedByType === $initiatedByType)
		{
			return $result;
		}

		$document->initiatedByType = $initiatedByType;
		if ($initiatedByType === InitiatedByType::EMPLOYEE)
		{
			// Employee mirrors company here: any stale external registration number and creation date
			// inherited from the company/HCM configuration are cleared (anti-leak), while
			// the source types are kept MANUAL. The actual values are filled in later on the document
			// creation step (see Operation\Document\Template\Send), which stores them as MANUAL so that
			// FieldValue::getB2eRegionalFieldValue returns a trusted value and the placeholder is filled.
			$document->externalId = null;
			$document->externalDateCreate = null;
			$document->externalDateCreateSourceType = Type\Document\ExternalDateCreateSourceType::MANUAL;
			$document->externalIdSourceType = Type\Document\ExternalIdSourceType::MANUAL;
			$document->hcmLinkDateSettingId = 0;
			$document->hcmLinkExternalIdSettingId = 0;
		}
		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return $result->addError(new Main\Error('Error when trying to save document'));
		}

		$successResult = new CreateDocumentResult($document);
		if (!$document->isTemplated())
		{
			return $successResult;
		}
		$template = $this->documentTemplateRepository->getById($document->templateId);
		if ($template === null)
		{
			return $successResult;
		}
		if ($template->status === Type\Template\Status::NEW && $template->visibility === Visibility::INVISIBLE)
		{
			return $successResult;
		}

		// When the initiator type is changed, user should complete the template creation process again because some data may be outdated. ticket: 0213028
		$template->status = Type\Template\Status::NEW;
		$template->visibility = Visibility::INVISIBLE;

		$result = $this->documentTemplateRepository->update($template);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $successResult;
	}

	public function setResultFileId(Item\Document $document, int $resultFileId): Main\Result
	{
		$document->resultFileId = $resultFileId;

		return DocumentTable::update($document->id, ['RESULT_FILE_ID' => $resultFileId]);
	}

	public function modifyRepresentativeId(
		string $documentUid,
		int $representativeId,
		string $entityType,
	): Main\Result
	{
		$result = new Main\Result();

		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		if (Main\UserTable::getCount(['=ID' => $representativeId]) < 1 && $entityType === Type\Member\EntityType::COMPANY)
		{
			return $result->addError(new Main\Error("User with id $representativeId does not exist"));
		}

		if (
			$entityType === Type\Member\EntityType::ROLE
			&& $this->structureNodeService->getRoleNameById($representativeId) === null
		)
		{
			return $result->addError(new Main\Error("Role with id $representativeId does not exist"));
		}

		$document->representativeId = $representativeId;

		return $this->documentRepository->update($document);
	}

	public function modifyCompany(string $documentUid, string $companyUid, int $companyEntityId): Main\Result
	{
		$result = new Main\Result();

		if ($companyUid === '' || $companyEntityId < 1)
		{
			return $result->addError(new Main\Error('Company is empty'));
		}

		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document || !$this->canBeChanged($document))
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$document->companyUid = $companyUid;
		$document->companyEntityId = $companyEntityId;
		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return $result->addError(new Main\Error('Error when trying to save document'));
		}

		return $result;
	}

	public function modifyProviderCode(Item\Document $document, string $providerCode): Main\Result
	{
		$result = new Main\Result();
		if (!Type\ProviderCode::isValid($providerCode))
		{
			return $result->addError(new Main\Error("Invalid provider code. `$providerCode`"));
		}

		if (!$this->canBeChanged($document))
		{
			return $result->addError(new Main\Error('Document cannot be changed'));
		}

		return $this->providerCodeService->updateProviderCode($document, $providerCode);
	}

	/**
	 * Sets the provider code of exactly one document.
	 *
	 * @see self::modifyProviderCode() spreads the code over the company documents instead.
	 */
	public function modifyProviderCodeForSingleDocument(Item\Document $document, string $providerCode): Main\Result
	{
		if (!$this->canBeChanged($document))
		{
			return (new Main\Result())->addError(new Main\Error('Document cannot be changed'));
		}

		return $this->providerCodeService->updateProviderCodeToDocument($document, $providerCode);
	}

	/**
	 * Saves the message attached to the invitation to sign on a single document; a whole batch of one
	 * send goes through modifyInviteMessageForDocuments(). Outside of these two the attribute is only
	 * written by the operations that create the document.
	 *
	 * The status is checked twice, and both checks are needed: the guard below refuses with the error
	 * code of the reason, while the write itself repeats the status condition in its `WHERE` clause.
	 * Without the second check the document read before the write and the document written to are not
	 * the same state — `ConfigureDocument` of a neighbouring document of the batch runs in parallel and
	 * moves this one to `ready`, and the text would land on a document whose invitations are already
	 * being formed.
	 *
	 * @param string|null $message null, an empty string or whitespace only clears the previously saved message
	 */
	public function modifyInviteMessage(Item\Document $document, ?string $message): Main\Result
	{
		$validationResult = (new Validation\ValidateInviteMessage($message))->launch();
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$guardResult = $this->checkInviteMessageCanBeStored($document);
		if (!$guardResult->isSuccess())
		{
			return $guardResult;
		}

		$result = new Main\Result();
		if (!$document->id)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$normalizedMessage = self::normalizeInviteMessage($message);
		if (!$this->documentRepository->updateInviteMessageIfEditable($document->id, $normalizedMessage))
		{
			return $result->addError($this->createInviteMessageStatusError());
		}

		$document->inviteMessage = $normalizedMessage;

		return $result;
	}

	/**
	 * Saves one message on every document of a send batch. The batch of the group wizard carries a
	 * single text, so the whole batch is written by one conditional statement instead of one statement
	 * per document: the statement runs inside the transaction of the caller and every statement saved
	 * there is lock time saved on documents the wizard is about to configure.
	 *
	 * The guards of the single write run over the whole batch before that statement, so a refused
	 * document gives its own error code and nothing is written. The statement itself takes either all
	 * the documents or none of them: a document whose status changed after the guard is refused by the
	 * status condition of the write, and then the batch has to be taken back — that is the business of
	 * the caller, which owns the transaction.
	 *
	 * @param string|null $message null, an empty string or whitespace only clears the previously saved message
	 */
	public function modifyInviteMessageForDocuments(Item\DocumentCollection $documents, ?string $message): Main\Result
	{
		$validationResult = (new Validation\ValidateInviteMessage($message))->launch();
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$guardResult = $this->checkInviteMessageCanBeStoredForDocuments($documents);
		if (!$guardResult->isSuccess())
		{
			return $guardResult;
		}

		$result = new Main\Result();
		$documentIds = [];
		foreach ($documents as $document)
		{
			if (!$document->id)
			{
				return $result->addError(new Main\Error('Document not found'));
			}

			$documentIds[] = $document->id;
		}

		if ($documentIds === [])
		{
			return $result;
		}

		$normalizedMessage = self::normalizeInviteMessage($message);
		if (!$this->documentRepository->updateInviteMessageForEditableDocuments($documentIds, $normalizedMessage))
		{
			return $result->addError($this->createInviteMessageStatusError());
		}

		foreach ($documents as $document)
		{
			$document->inviteMessage = $normalizedMessage;
		}

		return $result;
	}

	/**
	 * The same guards over a whole batch: one rejected document rejects the batch, and its own error
	 * code says why. The batch entry point calls this before it opens its transaction — a refusal
	 * costs no transaction — and the batch write repeats the check, so the guards cannot be skipped.
	 */
	public function checkInviteMessageCanBeStoredForDocuments(Item\DocumentCollection $documents): Main\Result
	{
		foreach ($documents as $document)
		{
			$guardResult = $this->checkInviteMessageCanBeStored($document);
			if (!$guardResult->isSuccess())
			{
				return $guardResult;
			}
		}

		return new Main\Result();
	}

	private function createInviteMessageStatusError(): Main\Error
	{
		return new Main\Error(
			Loc::getMessage('SIGN_SERVICE_DOCUMENT_INVITE_MESSAGE_DOCUMENT_IN_FINAL_STATUS'),
			self::ERROR_CODE_DOCUMENT_IN_FINAL_STATUS,
		);
	}

	/**
	 * Every reason to refuse the write in one place: the batch entry point checks the whole batch
	 * before its first write and has to refuse for the same reasons and with the same error codes.
	 *
	 * Only a B2E invitation sent by the company carries the message: HrBotMessageService passes the
	 * text on to the employee signer and no other invitation reads it. Both conditions are required
	 * explicitly, so a document that carries neither sign is refused instead of storing a value that
	 * would be silently undeliverable. The client hides the field in the same cases.
	 *
	 * The status window is narrower than the one of canBeChanged(): the text is read when the
	 * invitation is formed, and that happens on the callback of a document already sent, so `ready` is
	 * too late to change it. A batch of a group wizard makes it visible — configure walks the batch
	 * document by document, so a write allowed in `ready` would give the invitations of one send
	 * different versions of the same message.
	 */
	public function checkInviteMessageCanBeStored(Item\Document $document): Main\Result
	{
		$result = new Main\Result();

		if (!Type\DocumentScenario::isB2eScenarioByDocument($document))
		{
			return $result->addError(new Main\Error(
				Loc::getMessage('SIGN_SERVICE_DOCUMENT_INVITE_MESSAGE_DOCUMENT_NOT_B2E'),
				self::ERROR_CODE_DOCUMENT_NOT_B2E,
			));
		}

		if ($document->initiatedByType !== InitiatedByType::COMPANY)
		{
			return $result->addError(new Main\Error(
				Loc::getMessage('SIGN_SERVICE_DOCUMENT_INVITE_MESSAGE_NOT_INITIATED_BY_COMPANY'),
				self::ERROR_CODE_DOCUMENT_NOT_INITIATED_BY_COMPANY,
			));
		}

		if (!Type\DocumentStatus::isEditableByDocument($document))
		{
			return $result->addError(new Main\Error(
				Loc::getMessage('SIGN_SERVICE_DOCUMENT_INVITE_MESSAGE_DOCUMENT_IN_FINAL_STATUS'),
				self::ERROR_CODE_DOCUMENT_IN_FINAL_STATUS,
			));
		}

		return $result;
	}

	/**
	 * A message made of whitespace only means no message. Without this the three delivery channels
	 * disagree on the same value: the invitation body gets a block with a blank line under its title,
	 * the mobile card gets an empty gap, and the web card shows nothing because it trims on render.
	 * Every entry point that stores the attribute normalizes through here.
	 */
	public static function normalizeInviteMessage(?string $message): ?string
	{
		if ($message === null)
		{
			return null;
		}

		// The character class the browser trims by, so the value the client counts and the value the
		// server stores stay the same; on broken UTF-8 the pattern returns null and the ASCII trim of
		// the fallback still keeps a blank message out of the delivery channels.
		$trimmed = preg_replace('~^[\s\pZ\x{FEFF}]+|[\s\pZ\x{FEFF}]+$~u', '', $message);
		$message = trim($trimmed ?? $message);

		return $message === '' ? null : $message;
	}

	public function update(Item\Document $document): Main\Result
	{
		return $this->documentRepository->update($document);
	}

	private function insertToDB(
		string $title,
		Item\Blank $blank,
		Item\Document $documentItem,
		int $createdById = 0,
		?BindingCollection $bindings = null,
		?bool $checkPermission = null,
	): Main\Result
	{
		// backward compatibility
		if ($documentItem->entityType === null)
		{
			$documentItem->entityType = EntityType::SMART;
		}

		if ($createdById)
		{
			$documentItem->createdById = $createdById;
		}

		if ((int)$documentItem->entityId === 0 && !$documentItem->isTemplated())
		{
			$result = $this->documentEntityFactory->createNewEntity(
				$documentItem,
				$checkPermission ?? $this->checkPermission,
			);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$documentItem->entityId = $result->getId();
		}

		$entity = null;
		if (!$documentItem->isTemplated())
		{
			$entity = $this->documentEntityFactory->getByDocument($documentItem);
			if ($entity === null && $documentItem->entityType)
			{
				return (new Main\Result())->addError(new Main\Error("Document doesnt contains linked entity"));
			}
		}

		$documentTitle = $this->makeDocumentTitle($title, $blank, $entity);

		// linked smart-document also needs to be renamed
		if ($documentTitle !== $entity?->getTitle())
		{
			$entity?->setTitle($documentTitle);
		}

		$notB2e = $documentItem->entityType !== Type\Document\EntityType::SMART_B2E;

		$documentItem->title = $documentTitle;
		$documentItem->langId = Context::getCurrent()->getLanguage();
		$documentItem->status = DocumentStatus::NEW;
		$documentItem->blankId = $blank->id;
		$documentItem->initiator = $this->createInitiatorName($notB2e);

		/* @todo Default scenarios for these type of scenarios, change logic when other scenarios are going to be implemented */
		$documentItem->scenario = match ($documentItem->entityType) {
			Type\Document\EntityType::SMART_B2E => Type\DocumentScenario::DSS_SECOND_PARTY_MANY_MEMBERS,
			default => Type\DocumentScenario::SIMPLE_SIGN_MANY_PARTIES_ONE_MEMBERS,
		};
		$documentItem->version = Version::CURRENT;

		$documentItem->entityTypeId = EntityType::getEntityTypeIdByType($documentItem->entityType);
		$addResult = Service\Container::instance()
			->getDocumentRepository()
			->add($documentItem);

		if (!$addResult->isSuccess())
		{
			return $addResult;
		}

		if ($bindings !== null)
		{
			$addBindingsResult = $this->addBindings($documentItem, $bindings);

			if (!$addBindingsResult->isSuccess())
			{
				return $addBindingsResult;
			}
		}

		if (!$documentItem->isTemplated())
		{
			$eventData = new EventData();
			$eventData
				->setEventType(EventData::TYPE_ON_REGISTER)
				->setDocumentItem($documentItem)
			;

			try
			{
				$this->eventHandlerService->createTimelineEvent($eventData);
			}
			catch (ArgumentException|Main\ArgumentOutOfRangeException $e)
			{
			}
		}

		return $addResult;
	}

	private function addBindings(Item\Document $document, BindingCollection $bindings): Result
	{
		$result = new Result();
		if ((int)$document->entityId < 1)
		{
			return $result->addError(new Main\Error('Invalid document entity id'));
		}

		foreach ($bindings as $binding)
		{
			if ($binding->entityId < 1)
			{
				continue;
			}

			if ($binding->entityType < 1)
			{
				continue;
			}

			$addResult = Container::instance()
				->getCrmEntityRelationService()
				->addRelationToSmartB2eDocument($document, $binding->entityId, $binding->entityType)
			;

			$result->addErrors($addResult->getErrors());
		}

		return $result;
	}

	/**
	 * @return string
	 * @see \SignMasterComponent::getResponsibleName
	 */
	private function createInitiatorName(bool $fromPrevious = true): string
	{
		if ($fromPrevious)
		{
			$previousInitiator = $this->getPreviousDocumentInitiatorName();
			if ($previousInitiator !== null)
			{
				return $previousInitiator;
			}
		}

		return User::getCurrentUserName();
	}

	private function getPreviousDocumentInitiatorName(): ?string
	{
		$currentUserId = Main\Engine\CurrentUser::get()->getId();

		if ($currentUserId === null)
		{
			return null;
		}

		$lastUserDocuments = $this->getUserLastDocuments(
			(int)$currentUserId,
			5,
		);

		foreach ($lastUserDocuments as $userDocument)
		{
			$initiatorName = $userDocument->initiator;
			if ($initiatorName !== null)
			{
				return $initiatorName;
			}
		}

		return null;
	}

	public function changeBlank(
		string $uid,
		int $blankId,
		bool $copyBlocksFromPreviousBlank = false,
		bool $deleteOldBlank = true,
		bool $autoDetectType = false,
	): Main\Result
	{
		['document' => $document, 'blank' => $blank, 'result' => $extractionResult] = $this->extractDocumentAndBlank($uid, $blankId);

		if (!$extractionResult->isSuccess())
		{
			return $extractionResult;
		}

		if (
			$blank->scenario === Type\BlankScenario::B2B && in_array($document->scenario, Type\DocumentScenario::getB2EScenarios(), true)
			|| $blank->scenario === Type\BlankScenario::B2E && in_array($document->scenario, Type\DocumentScenario::getB2BScenarios(), true)
		)
		{
			return (new Main\Result())->addError(new Main\Error('Wrong blank scenario for current document'));
		}

		if (
			!$autoDetectType
			&& Type\DocumentScenario::isB2EScenario($document->scenario)
			&& $this->documentRepository->getCountByBlankId($blankId) === 0
		)
		{
			$this->blankService->changeBlankTitleByDocument($document, $document->title);
		}

		$oldBlankId = $document->blankId;
		$oldBlank = $oldBlankId === null ? null : $this->blankRepository->getById($oldBlankId);
		if ($autoDetectType)
		{
			if (
				!Type\DocumentScenario::isB2EScenario($document->scenario)
				|| !Type\DocumentStatus::isEditableByDocument($document)
				|| $oldBlank === null
				|| $this->documentRepository->getCountByBlankId($blankId) > 0
			)
			{
				return (new Main\Result())->addError(new Main\Error('Cannot replace document blank'));
			}

			$files = new Item\Fs\FileCollection();
			foreach ($blank->fileCollection ?? [] as $file)
			{
				$validationFile = clone $file;
				$validationFile->type = pathinfo($file->name, PATHINFO_EXTENSION);
				$files->addItem($validationFile);
			}
			$validation = $this->blankService->validateAutoDetectionFiles($files, $blank->scenario);
			if (!$validation->isSuccess())
			{
				return $validation;
			}

			$blank->hasPlaceholders = mb_strtolower((string)$files->first()->type) === 'docx';
			$result = $this->blankRepository->updateHasPlaceholders($blankId, $blank->hasPlaceholders);
			if (!$result->isSuccess())
			{
				return $result;
			}
			$copyBlocksFromPreviousBlank = !$oldBlank->hasPlaceholders && !$blank->hasPlaceholders;
		}

		if ($copyBlocksFromPreviousBlank && $oldBlank !== null && $oldBlankId !== $blankId)
		{
			try
			{
				$this->blockRepository->loadBlocks($oldBlank);
				$newBlocks = $oldBlank->blockCollection->copyWithBlackId($blankId);
				$result = $this->blockRepository->addCollection($newBlocks);
			}
			catch (\Throwable $exception)
			{
				$result = (new Main\Result())->addError(Main\Error::createFromThrowable($exception));
			}
			if (!$result->isSuccess())
			{
				return $result->setData(['oldBlankId' => $oldBlankId]);
			}
		}

		$document->blankId = $blankId;
		try
		{
			$result = $this->documentRepository->update($document);
		}
		catch (\Throwable $exception)
		{
			$result = (new Main\Result())->addError(Main\Error::createFromThrowable($exception));
		}
		if (!$result->isSuccess())
		{
			$rollbackResult = $this->rollbackBlankChange($document, $oldBlankId);
			$result->addErrors($rollbackResult->getErrors());

			return $result->setData([
				'preserveNewBlank' => !$rollbackResult->isSuccess(),
				'oldBlankId' => $oldBlankId,
			]);
		}

		if ($deleteOldBlank)
		{
			$result = (new Delete($oldBlank))->launch();
			$skippableErrors = [Delete::ERROR_BLANK_USED_IN_DOCUMENTS, Delete::ERROR_BLANK_USED_FOR_RESENT_DOCUMENTS];
			$isErrorSkippable = in_array($result->getError()?->getCode(), $skippableErrors, true);

			if (!$result->isSuccess() && !$isErrorSkippable)
			{
				return $result;
			}
		}

		return \Bitrix\Sign\Result\Result::createByData([
			'document' => $document,
			'oldBlankId' => $oldBlankId,
		]);
	}

	/**
	 * Replace the document blank on the portal and in the signing service when necessary
	 */
	public function changeBlankAndUpload(
		string $documentUid,
		int $newBlankId,
		bool $copyBlocksFromPreviousBlank = false,
		bool $autoDetectType = false,
	): Main\Result
	{
		try
		{
			$changeBlankResult = $this->changeBlank(
				$documentUid,
				$newBlankId,
				$copyBlocksFromPreviousBlank,
				deleteOldBlank: false,
				autoDetectType: $autoDetectType,
			);
		}
		catch (\Throwable $exception)
		{
			return (new Main\Result())->addError(Main\Error::createFromThrowable($exception));
		}
		if (!$changeBlankResult->isSuccess())
		{
			if (!($changeBlankResult->getData()['preserveNewBlank'] ?? false))
			{
				$this->tryDeleteBlank(
					$newBlankId,
					skipForTemplateCheck: true,
					preserveFilesFromBlankId: $changeBlankResult->getData()['oldBlankId'] ?? null,
				);
			}

			return $changeBlankResult;
		}

		$result = new Main\Result();
		$document = $changeBlankResult->getData()['document'] ?? null;
		$oldBlankId = $changeBlankResult->getData()['oldBlankId'] ?? null;

		if (!$document instanceof Item\Document)
		{
			return $result->addError(new Main\Error('Cannot change document blank'));
		}

		if (!$document->uid || $document->uid !== $documentUid)
		{
			$uploadResult = (new Main\Result())->addError(new Main\Error('Invalid document uid after blank change'));
		}
		else
		{
			try
			{
				$uploadResult = $this->upload($document->uid);
			}
			catch (\Throwable $exception)
			{
				$uploadResult = (new Main\Result())->addError(Main\Error::createFromThrowable($exception));
			}
		}

		if (!$uploadResult->isSuccess())
		{
			$rollbackResult = $this->rollbackBlankChange($document, $oldBlankId);
			if ($rollbackResult->isSuccess() && $newBlankId !== $oldBlankId)
			{
				$this->tryDeleteBlank($newBlankId, skipForTemplateCheck: true, preserveFilesFromBlankId: $oldBlankId);
			}

			return $result->addErrors($uploadResult->getErrors())->addErrors($rollbackResult->getErrors());
		}

		if ($oldBlankId !== null && $oldBlankId !== $document->blankId)
		{
			try
			{
				if ($document->id !== null)
				{
					Container::instance()
						->getDocumentPlaceholderCacheService()
						->deleteRawPlaceholders($document->id, $oldBlankId)
					;
				}

				$this->tryDeleteBlank(
					$oldBlankId,
					skipForTemplateCheck: $autoDetectType,
					preserveFilesFromBlankId: $document->blankId,
				);
			}
			catch (\Throwable $exception)
			{
				Container::instance()->getLogger()->error('Cannot clean up replaced document blank', [
					'documentId' => $document->id,
					'blankId' => $oldBlankId,
					'exception' => $exception,
				]);
			}
		}

		return \Bitrix\Sign\Result\Result::createByData(['document' => $document]);
	}

	private function rollbackBlankChange(Item\Document $document, ?int $oldBlankId): Main\Result
	{
		if ($oldBlankId === null)
		{
			return (new Main\Result())->addError(new Main\Error('Cannot restore document blank', 'BLANK_RESTORE_FAILED'));
		}

		$newBlankId = $document->blankId;
		$document->initOriginal();
		$document->blankId = $oldBlankId;
		try
		{
			$result = $this->documentRepository->update($document);
		}
		catch (\Throwable $exception)
		{
			$result = (new Main\Result())->addError(Main\Error::createFromThrowable($exception));
		}
		if (!$result->isSuccess())
		{
			$document->blankId = $newBlankId;
			$result->addError(new Main\Error('Cannot restore document blank', 'BLANK_RESTORE_FAILED'));
		}

		return $result;
	}

	private function tryDeleteBlank(
		?int $blankId,
		bool $skipForTemplateCheck = false,
		?int $preserveFilesFromBlankId = null,
	): void
	{
		if ($blankId === null)
		{
			return;
		}

		$blank = $this->blankRepository->getById($blankId);
		if ($blank !== null)
		{
			if ($preserveFilesFromBlankId !== null)
			{
				$retainedFileIds = [];
				foreach ($this->blockRepository->getCollectionByBlankId($preserveFilesFromBlankId) as $block)
				{
					$fileId = $block->data['fileId'] ?? null;
					if (is_numeric($fileId))
					{
						$retainedFileIds[(int)$fileId] = true;
					}
				}
				$this->blockRepository->loadBlocks($blank);
				foreach ($blank->blockCollection ?? new Item\BlockCollection() as $block)
				{
					if (isset($retainedFileIds[(int)($block->data['fileId'] ?? 0)]))
					{
						// The surviving blank still references this file.
						unset($block->data['fileId']);
					}
				}
			}
			$result = (new Delete($blank, $skipForTemplateCheck))->launch();
			if (
				!$result->isSuccess()
				&& !in_array($result->getError()?->getCode(), [
					Delete::ERROR_BLANK_USED_IN_DOCUMENTS,
					Delete::ERROR_BLANK_USED_FOR_RESENT_DOCUMENTS,
				], true)
			)
			{
				Container::instance()->getLogger()->error('Cannot delete document blank', [
					'blankId' => $blankId,
					'errors' => $result->getErrorMessages(),
				]);
			}
		}
	}

	/**
	 * Change "sign until" date for document
	 *
	 * @param string $uid
	 * @param Main\Type\DateTime|null $dateSignUntil
	 *
	 * @return \Bitrix\Main\Result
	 */
	public function modifyDateSignUntil(string $uid, ?Main\Type\DateTime $dateSignUntil): Main\Result
	{
		$result = new Main\Result;
		$document = $this->documentRepository->getByUid($uid);

		if (!$document)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$document->dateSignUntil = $dateSignUntil;

		$validationResult = (new Validation\ValidateDateSignUntil($document))->launch();
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$api = Service\Container::instance()->getApiDocumentService();
		$request = new Item\Api\Document\ModifyDateSignUntilRequest($document->uid, $document->dateSignUntil->getTimestamp());
		$response = $api->modifyDateSignUntil($request);
		if (!$response->isSuccess())
		{
			return (new Main\Result())->addErrors($response->getErrors());
		}

		return $this->saveDateSignUntilTakenByService($document);
	}

	/**
	 * The signing service has taken the date already, so whatever the local write of it fails with,
	 * a refused write or a query that threw, the caller is told the change stays applied. The
	 * timeline event is a side effect of the write and not the write itself: it is logged where it
	 * fails and does not make a saved date unsaved.
	 *
	 * @see \Bitrix\Sign\Item\Document\Configure\Step::isExternalApplied() reads the mark of the result
	 */
	private function saveDateSignUntilTakenByService(Item\Document $document): Main\Result
	{
		try
		{
			$updateResult = $this->documentRepository->update($document);
		}
		catch (\Throwable $exception)
		{
			$this->logDocumentFailure(
				'Failed to save the sign until date of {documentUid}: {errorsText}',
				$document,
				$exception,
			);

			return self::makeDateSignUntilNotSavedResult();
		}

		if (!$updateResult->isSuccess())
		{
			return self::makeDateSignUntilNotSavedResult();
		}

		try
		{
			$this->fireDocumentUpdatedTimelineEvent($document);
		}
		catch (\Throwable $exception)
		{
			$this->logDocumentFailure(
				'Failed to emit the update event of {documentUid}: {errorsText}',
				$document,
				$exception,
			);
		}

		return $updateResult;
	}

	/**
	 * The date the signing service has taken and the document could not keep: the mark tells the
	 * caller the change stays applied and no local compensation can take it back.
	 */
	private static function makeDateSignUntilNotSavedResult(): Main\Result
	{
		return Item\Document\Configure\Step::markExternalApplied(
			(new Main\Result())->addError(
				new Main\Error('Error when trying to save document', self::ERROR_DOCUMENT_SAVE_FAILED),
			),
		);
	}

	private function logDocumentFailure(string $message, Item\Document $document, \Throwable $exception): void
	{
		Container::instance()->getLogger('Service')->error(
			$message,
			[
				'documentUid' => $document->uid,
				'errorsText' => $exception->getMessage(),
			],
		);
	}

	/**
	 * Upload document file to signing server
	 *
	 * @param string $uid
	 *
	 * @return \Bitrix\Main\Result
	 */
	public function upload(string $uid, bool $skipReuse = false): Main\Result
	{
		['document' => $document, 'blank' => $blank, 'result' => $extractionResult] = $this->extractDocumentAndBlank(
			$uid,
		);
		if (!$extractionResult->isSuccess())
		{
			return $extractionResult;
		}

		if (!$skipReuse)
		{
			$reuseResult = $this->reuse($document->uid, $blank->getId());
			if ($reuseResult->isSuccess())
			{
				$operation = new ChangeDocumentStatus($document, Type\DocumentStatus::UPLOADED);
				return $operation
					->launch()
					->setData(['document' => $operation->getDocument()])
				;
			}
		}

		$fileCollection = new Item\Api\Property\Request\Document\Upload\FileCollection();

		foreach ($blank->fileCollection->toArray() as $file)
		{
			if (empty($file->content->data))
			{
				return (new Main\Result())->addError(
					new Main\Error(Loc::getMessage('SIGN_SERVICE_DOCUMENT_FILE_EMPTY'), 'FILE_EMPTY'),
				);
			}

			$fileCollection->addItem(
				new Item\Api\Property\Request\Document\Upload\File(
					$file->name, $file->type, base64_encode($file->content->data),
				),
			);
		}
		$documentUploadRequest = new Item\Api\Document\UploadRequest($uid, $fileCollection);
		$apiDocument = Service\Container::instance()
			->getApiDocumentService()
		;
		$documentUploadResponse = $apiDocument->upload($documentUploadRequest);

		if (!$documentUploadResponse->isSuccess())
		{
			return (new Main\Result())->addErrors($documentUploadResponse->getErrors());
		}

		$operation = new ChangeDocumentStatus($document, Type\DocumentStatus::UPLOADED);
		return $operation
			->launch()
			->setData(['document' => $operation->getDocument()])
		;
	}

	public function getEditUrl(string $uid, int $userId): Main\Result
	{
		$result = new Main\Result();

		['document' => $document, 'blank' => $blank, 'result' => $extractionResult] = $this->extractDocumentAndBlank($uid);
		if (!$extractionResult->isSuccess())
		{
			return $extractionResult;
		}

		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return $result->addError(new Main\Error('Editing is only available for B2E documents'));
		}

		if (!Type\DocumentStatus::isEditableByDocument($document))
		{
			return $result->addError(new Main\Error('Document status does not allow editing'));
		}

		return $this->diskService->getEditUrl($blank, $userId, $document->title, $uid);
	}

	public function applyEditedFile(string $documentUid, int $diskFileId, int $userId): Main\Result
	{
		$result = new Main\Result();

		$validateResult = $this->diskService->getValidatedDiskFileById($diskFileId, $userId);
		if (!$validateResult->isSuccess())
		{
			return $result->addErrors($validateResult->getErrors());
		}

		/** @var \Bitrix\Disk\File $diskFile */
		$diskFile = $validateResult->getData()['diskFile'];

		['document' => $document, 'blank' => $blank, 'result' => $extractionResult] = $this->extractDocumentAndBlank($documentUid);
		if (!$extractionResult->isSuccess())
		{
			$this->diskService->deleteDiskFile($diskFile, $userId);

			return $extractionResult;
		}

		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			$this->diskService->deleteDiskFile($diskFile, $userId);

			return $result->addError(new Main\Error('Editing is only available for B2E documents'));
		}

		if (!Type\DocumentStatus::isEditableByDocument($document))
		{
			$this->diskService->deleteDiskFile($diskFile, $userId);

			return $result->addError(new Main\Error('Document status does not allow editing'));
		}

		$cloneResult = $this->cloneDiskFile($diskFile);
		if (!$cloneResult->isSuccess())
		{
			$this->diskService->deleteDiskFile($diskFile, $userId);

			return $result->addErrors($cloneResult->getErrors());
		}
		$clonedFileId = $cloneResult->getData()['fileId'];

		$blankService = $this->blankService;

		$blankResult = $blankService->createFromFileIds(
			[$clonedFileId],
			$blank->scenario ?? Type\BlankScenario::B2E,
			$blank->forTemplate,
			true,
		);
		if (!$blankResult->isSuccess())
		{
			\CFile::Delete($clonedFileId);
			$this->diskService->deleteDiskFile($diskFile, $userId);

			return $result->addErrors($blankResult->getErrors());
		}

		$newBlankId = $blankResult->getId();

		$changeResult = $this->changeBlankAndUpload(
			$documentUid,
			$newBlankId,
			copyBlocksFromPreviousBlank: !$blank->hasPlaceholders,
		);
		if (!$changeResult->isSuccess())
		{
			$blankService->rollbackById($newBlankId);
			$this->diskService->deleteDiskFile($diskFile, $userId);

			return $result->addErrors($changeResult->getErrors());
		}

		$this->diskService->deleteDiskFile($diskFile, $userId);

		$result->setData([
			'blankId' => $newBlankId,
			'document' => $changeResult->getData()['document'] ?? $document,
		]);

		return $result;
	}

	protected function cloneDiskFile(\Bitrix\Disk\File $diskFile): Main\Result
	{
		$result = new Main\Result();
		$fileId = $diskFile->getFileId();
		if (!$fileId)
		{
			return $result->addError(new Main\Error('Disk file has no associated CFile'));
		}

		$fileArray = \CFile::MakeFileArray($fileId);
		if (!$fileArray)
		{
			return $result->addError(new Main\Error('Failed to read file from disk'));
		}

		$diskFileName = $diskFile->getName();
		if ($diskFileName)
		{
			$fileArray['name'] = $diskFileName;
		}

		$clonedFileId = \CFile::SaveFile($fileArray, 'sign');
		if (!$clonedFileId)
		{
			return $result->addError(new Main\Error('Failed to clone file from disk'));
		}

		return $result->setData(['fileId' => $clonedFileId]);
	}

	public function discardEditedFile(int $diskFileId, int $userId): Main\Result
	{
		return $this->diskService->deleteDiskFileById($diskFileId, $userId);
	}

	/**
	 * Reuse document file on the signing server
	 *
	 * @param string $documentUid
	 * @param int $blankId
	 * @return Result
	 */
	private function reuse(string $documentUid, int $blankId): Main\Result
	{
		$result = new Main\Result();
		$apiDocument = Service\Container::instance()
			->getApiDocumentService();

		$lastDocumentByBlankId = $this->getLastByBlankId($blankId);

		if ($lastDocumentByBlankId === null)
		{
			return $result->addError(new Main\Error('Last document can not be empty'));
		}

		if ($lastDocumentByBlankId->uid === null)
		{
			return $result->addError(new Main\Error('Last document uid can not be empty'));
		}

		if ($lastDocumentByBlankId->uid === $documentUid)
		{
			return $result->addError(new Main\Error('No other document to reuse blank from'));
		}

		$documentReuseRequest = new Item\Api\Document\ReuseRequest($documentUid, $lastDocumentByBlankId->uid);
		$documentReuseResponse = $apiDocument->reuse($documentReuseRequest);
		if (!$documentReuseResponse->isSuccess())
		{
			return (new Main\Result())->addErrors($documentReuseResponse->getErrors());
		}

		return $result;
	}

	/**
	 * @param string $uid
	 * @param int|null $blankId
	 *
	 * @return array{document: Item\Document, blank: Item\Blank, result: Main\Result}
	 */
	public function extractDocumentAndBlank(string $uid, ?int $blankId = null): array
	{
		$result = (new Main\Result());
		try
		{
			$document = $this->documentRepository->getByUid($uid);
		}
		catch (Main\ObjectPropertyException|Main\ArgumentException|Main\SystemException $e)
		{
			$document = null;
		}

		if (!$document)
		{
			$result->addError(new Main\Error(Loc::getMessage('SIGN_SERVICE_DOCUMENT_NOT_FOUND')));
		}

		$blankId = $blankId ?: $document?->blankId;
		try
		{
			// A missing document leaves no blank id to look up: reading it off the document would
			// turn a reported error into a TypeError of the caller.
			$blank = $blankId === null ? null : $this->blankRepository->getById($blankId);
		}
		catch (Main\ObjectPropertyException|Main\ArgumentException|Main\SystemException $e)
		{
			$blank = null;
		}

		if (!$blank)
		{
			$result->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_DOCUMENT_BLANK_NOT_FOUND')),
			);
		}

		return [
			'document' => $document,
			'blank' => $blank,
			'result' => $result,
		];
	}

	/**
	 * Get document by uid or null if not found
	 *
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 */
	public function getByUid(string $uid): ?Item\Document
	{
		return $this->documentRepository->getByUid($uid);
	}

	public function getById(int $id): ?Item\Document
	{
		return $this->documentRepository->getById($id);
	}

	public function getUserLastDocuments(int $userId, int $limit = 10): Item\DocumentCollection
	{
		return $this->documentRepository->listLastByUserCreateId($userId, $limit);
	}

	/**
	 * Configure and start signing process
	 *
	 * @param string $uid
	 *
	 * @return \Bitrix\Main\Result|ConfigureResult
	 */
	public function configureAndStart(string $uid): Main\Result|ConfigureResult
	{
		return (new ConfigureFillAndStart($uid))->launch();
	}

	/**
	 * Get last document by blank id
	 *
	 * @param int $blankId
	 *
	 * @return \Bitrix\Sign\Item\Document|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getLastByBlankId(int $blankId): ?Item\Document
	{
		return $this->documentRepository->getLastByBlankId($blankId);
	}

	public function canEditBlank(Item\Document $document): bool
	{
		return in_array($document->status, [null, Type\DocumentStatus::NEW], true);
	}

	public function refreshEntityNumber(Item\Document $document): Main\Result
	{
		$entity = $this->documentEntityFactory->getByDocument($document);
		if ($entity === null)
		{
			return (new Main\Result())->addError(new Main\Error("Document doesnt contains linked crm entity"));
		}

		$entity->refreshNumber();

		return new Main\Result();
	}

	public function rollbackDocument(int $documentId): Main\Result
	{
		$document = $this->getById($documentId);
		if ($document === null)
		{
			return (new Main\Result())->addError(new Main\Error('Document not found'));
		}

		if (!in_array($document->entityType, EntityType::getAll(), true))
		{
			return (new Main\Result())->addError(new Main\Error('Invalid document entityType'));
		}

		$documentResult = $this->documentRepository->delete($document);
		if (!$documentResult->isSuccess())
		{
			return $documentResult;
		}

		if ($document->blankId !== null)
		{
			Container::instance()
				->getDocumentPlaceholderCacheService()
				->deleteRawPlaceholders($documentId, $document->blankId)
			;
		}

		$smartDocument = $this->documentEntityFactory->getByDocument($document);
		if ($smartDocument && $smartDocument->getId())
		{
			$sendDeleteEntityPullEventResult = (new SendDeleteEntityPullEvent($document))->launch();
			if (!$sendDeleteEntityPullEventResult->isSuccess())
			{
				return $sendDeleteEntityPullEventResult;
			}

			$smartDocumentDeleteResult = $smartDocument->delete();
			if (!$smartDocumentDeleteResult->isSuccess())
			{
				return $smartDocumentDeleteResult;
			}
		}

		$deleteMembersResult = $this->memberRepository->deleteAllByDocumentId($documentId);
		if (!$deleteMembersResult->isSuccess())
		{
			return $deleteMembersResult;
		}

		if ($document->templateId)
		{
			$templateFolderRelationResult = $this->templateFolderRelationRepository->deleteByIdAndType($document->templateId, Template\EntityType::TEMPLATE);
			if (!$templateFolderRelationResult->isSuccess())
			{
				return $templateFolderRelationResult;
			}

			$templateDeleteResult = $this->documentTemplateRepository->deleteById($document->templateId);
			if (!$templateDeleteResult->isSuccess())
			{
				return $templateDeleteResult;
			}
		}

		if ($document->blankId && $document->groupId === null)
		{
			// skip blank deletion, if assigned to documents
			if ($this->documentRepository->getCountByBlankId($document->blankId) > 0)
			{
				return new Main\Result();
			}

			$blank = $this->blankRepository->getById($document->blankId);
			if ($blank === null)
			{
				return (new Main\Result())->addError(new Main\Error('Blank not found'));
			}

			return $this->blankService->deleteWithResources($blank);
		}

		return new Main\Result();
	}

	public function rollbackDocumentByUid(string $uid): Main\Result
	{
		$document = $this->getByUid($uid);

		return $this->rollbackDocument($document->id);
	}

	public function canBeChanged(Item\Document $document): bool
	{
		if ($document->uid === null)
		{
			return true;
		}

		return !in_array(
			$document->status,
			DocumentStatus::getEnding(),
			true,
		);
	}

	/**
	 * Takes the snapshot of the document fields, of the member composition and of the company
	 * the smart process card points at, the document can be returned to.
	 *
	 * @param ?Item\Document\Configure\Plan $plan what the caller is going to apply, where it keeps such
	 *        a plan: the card is read only for a plan that can move the composition the card mirrors,
	 *        because only such a plan can move the company of the card. Null captures the whole state.
	 *
	 * @see self::restoreState()
	 */
	public function captureState(
		Item\Document $document,
		?Item\Document\Configure\Plan $plan = null,
	): Item\Document\State
	{
		return Item\Document\State::capture(
			$document,
			$this->memberRepository->listByDocumentId((int)$document->id),
			$plan === null || $plan->movesMemberComposition() ? $this->readMyCompanyId($document) : null,
		);
	}

	/**
	 * Null when the document has no card behind it: there is no company to know about, and the
	 * restore has nothing to put back.
	 */
	private function readMyCompanyId(Item\Document $document): ?int
	{
		$entity = $this->documentEntityFactory->getByDocument($document);

		return $entity !== null && $entity->getId() > 0 ? $entity->getCompanyId() : null;
	}

	/**
	 * Returns the document to the captured state, including the fields that were empty.
	 *
	 * @param ?Item\Document\Configure\Journal $journal what the caller has applied, where it keeps
	 *        such a journal: the parts of the snapshot the run never touched are left alone.
	 *
	 * @see self::captureState()
	 */
	public function restoreState(
		Item\Document\State $state,
		?Item\Document\Configure\Journal $journal = null,
	): Main\Result
	{
		return (new RestoreState($state, $journal))->launch();
	}

	private function makeDocNameFromBlank(Item\Blank $blank, ?Document\Entity\Dummy $entity = null): string
	{
		// generate doc name from blank (first file) name
		$name = \Bitrix\Main\IO\Path::replaceInvalidFilename($blank->title, fn() => '');
		$name = trim(str_replace('.' . Path::getExtension($name ?? ''), '', Path::getName($name ?? '')));
		$ellipsis = mb_strlen($name) > self::DOCUMENT_NAME_LENGTH_LIMIT ? '...' : '';
		$name = mb_substr($name, 0, self::DOCUMENT_NAME_LENGTH_LIMIT);
		$name = removeScriptExtension($name);
		$name .= $ellipsis;

		if ($name === '')
		{
			return $entity?->getTitle() ?? 'Document';
		}

		return Loc::getMessage(
			'SIGN_SERVICE_DOCUMENT_TITLE_PLACEHOLDER',
			[
				'#DOC_NAME#' => $name,
				'#DATE#' => Main\Type\Date::createFromTimestamp(time())->toString(),
			],
		);
	}

	public function modifyRegionDocumentType(string $documentUid, string $regionDocumentType): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document || !$this->canBeChanged($document))
		{
			return (new Main\Result())->addError(new Main\Error('Document not found'));
		}

		$document->regionDocumentType = $regionDocumentType;
		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return (new Main\Result())->addError(new Main\Error('Error when trying to save document'));
		}

		return new Main\Result();
	}

	public function modifyExternalId(
		string $documentUid,
		?string $externalId,
		Type\Document\ExternalIdSourceType $sourceType,
		?int $hcmLinkSettingId,
	): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document || !$this->canBeChanged($document))
		{
			return (new Main\Result())->addError(new Main\Error('Document not found'));
		}

		if ($sourceType === Type\Document\ExternalIdSourceType::MANUAL)
		{
			if (empty((string)$externalId))
			{
				return (new Main\Result())->addError(new Main\Error('ExternalId is empty'));
			}

			$document->externalId = trim($externalId);
			$document->hcmLinkExternalIdSettingId = 0;
		}
		else
		{
			$checkDocumentIntegrationResult = $this->checkDocumentIntegration($document);
			if (!$checkDocumentIntegrationResult->isSuccess())
			{
				return $checkDocumentIntegrationResult;
			}

			if ((int)$hcmLinkSettingId < 1)
			{
				return (new Main\Result())->addError(new Main\Error('Empty hcmLinkSettingId'));
			}

			if (!Container::instance()->getHcmLinkFieldService()->isExternalIdSettingFieldById($hcmLinkSettingId))
			{
				return (new Main\Result())->addError(new Main\Error('Invalid hcmLinkSettingId'));
			}

			$document->externalId = null;
			$document->hcmLinkExternalIdSettingId = $hcmLinkSettingId;
		}
		$document->externalIdSourceType = $sourceType;

		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return (new Main\Result())->addError(new Main\Error('Error when trying to save document'));
		}

		if ($sourceType === Type\Document\ExternalIdSourceType::MANUAL)
		{
			$this->refreshSmartDocumentTitle($document);
		}

		return new Main\Result();
	}

	public function modifyHcmLinkDocumentType(
		string $documentUid,
		?int $hcmLinkSettingId
	): Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document || !$this->canBeChanged($document))
		{
			return (new Main\Result())->addError(new Main\Error('Document not found'));
		}

		if ($hcmLinkSettingId > 0)
		{
			$checkDocumentIntegrationResult = $this->checkDocumentIntegration($document);
			if (!$checkDocumentIntegrationResult->isSuccess())
			{
				return $checkDocumentIntegrationResult;
			}

			if (!Container::instance()->getHcmLinkFieldService()->isDocumentTypeSettingFieldById($hcmLinkSettingId))
			{
				return (new Main\Result())->addError(new Main\Error('Invalid hcmLinkSettingId'));
			}
		}

		$document->hcmLinkDocumentTypeSettingId = $hcmLinkSettingId ?? 0;

		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return (new Main\Result())->addError(new Main\Error('Error when trying to save document'));
		}

		return new Main\Result();
	}

    public function modifyScheme(string $documentUid, string $scheme): Main\Result
    {
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document || !$this->canBeChanged($document))
		{
			return (new Main\Result())->addError(new Main\Error('Document not found'));
		}

		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return (new Main\Result())->addError(new Main\Error('Modification of scheme is only available for documents of b2e scenario'));
		}

		if (!Type\Document\SchemeType::isValid($scheme))
		{
			return (new Main\Result())->addError(new Main\Error('Invalid scheme type'));
		}

		$document->scheme = $scheme;
		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return (new Main\Result())->addError(new Main\Error('Error when trying to save document'));
		}

		return new Main\Result();
    }

	public function unsetEntityId(Item\Document $document): Result
	{
		return $this->documentRepository->unsetEntityId($document);
	}

	public function isCurrentUserCanEditDocument(Item\Document $document): bool
	{
		$result = (new CheckDocumentAccess(
			$document,
			PermissionDictionary::SIGN_CRM_SMART_B2E_DOC_WRITE,
		))->launch();

		return $result->isSuccess();
	}

	public function modifyExternalDate(
		string $documentUid,
		Type\Document\ExternalDateCreateSourceType $sourceType,
		?string $externalDate = null,
		?int $hcmLinkSettingId = null,
	): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document || !$this->canBeChanged($document))
		{
			return (new Main\Result())->addError(new Main\Error('Document not found'));
		}

		if ($sourceType == Type\Document\ExternalDateCreateSourceType::MANUAL)
		{
			if (empty($externalDate))
			{
				return (new Main\Result())->addError(new Main\Error('Empty date'));
			}

			\CTimeZone::Disable();
			try
			{
				$date = Main\Type\DateTime::createFromUserTime($externalDate);
			}
			catch (Main\ObjectException $exception)
			{
				\CTimeZone::Enable();

				return (new Main\Result())->addError(new Main\Error('Incorrect date format'));
			}

			$document->externalDateCreateSourceType = Type\Document\ExternalDateCreateSourceType::MANUAL;
			$document->externalDateCreate = $date->disableUserTime();
			$document->hcmLinkDateSettingId = 0;
		}
		else
		{
			$checkDocumentIntegrationResult = $this->checkDocumentIntegration($document);
			if (!$checkDocumentIntegrationResult->isSuccess())
			{
				return $checkDocumentIntegrationResult;
			}

			if (empty($hcmLinkSettingId))
			{
				return (new Main\Result())->addError(new Main\Error('Empty hcmLinkSettingId'));
			}

			if (!Container::instance()->getHcmLinkFieldService()->isDateSettingFieldById($hcmLinkSettingId))
			{
				return (new Main\Result())->addError(new Main\Error('Invalid hcmLinkSettingId'));
			}

			$document->externalDateCreateSourceType = Type\Document\ExternalDateCreateSourceType::HCMLINK;
			$document->externalDateCreate = null;
			$document->hcmLinkDateSettingId = $hcmLinkSettingId;
		}

		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return (new Main\Result())->addError(new Main\Error('Error when trying to save document'));
		}

		return new Main\Result();
	}

	private function checkDocumentIntegration(Item\Document $document): Main\Result
	{
		$result = new Main\Result();
		if (!$this->isIntegrationDocumentSettingsAvailableForProvider($document->providerCode))
		{
			return $result->addError(new Main\Error('Invalid document provider code'));
		}

		if (!Container::instance()->getHcmLinkService()->isAvailable())
		{
			return $result->addError(new Main\Error('Integration is not available', 'HCM_LINK_NOT_AVAILABLE'));
		}

		if ((int)$document->hcmLinkCompanyId < 1)
		{
			return $result->addError(new Main\Error('Incorrect hcmLinkCompanyId', 'HCM_LINK_NOT_AVAILABLE'));
		}

		if (!Container::instance()->getHcmLinkService()->isCompanyExistWithId((int)$document->hcmLinkCompanyId))
		{
			return $result->addError(new Main\Error('HcmLinkCompany not found', 'HCM_LINK_NOT_AVAILABLE'));
		}

		return $result;
	}

	public function modifyHcmLinkCompanyId(string $documentUid, ?int $hcmLinkCompanyId = null): ?Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document || !$this->canBeChanged($document))
		{
			return (new Main\Result())->addError(new Main\Error('Document not found'));
		}

		$document->hcmLinkCompanyId = $hcmLinkCompanyId ?? 0;
		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return (new Main\Result())->addError(new Main\Error('Error when trying to save document'));
		}

		return new Main\Result();
	}

	public function resolveDocumentByCrmEntity(string $entityType, int $entityId): ?Item\Document
	{
		return $this->documentRepository
			->getByEntityIdAndType($entityId, $entityType)
		;
	}

	public function getSignDocumentBySmartDocumentId(int $entityId): ?Item\Document
	{
		return $this->resolveDocumentByCrmEntity(EntityType::SMART, $entityId);
	}

	private function makeTemplateForDocument(
		Item\Document $document,
		string $title,
		int $currentUserId,
	): Result|CreateTemplateResult
	{
		$template = new Item\Document\Template(
			title: $title ?: 'Template title',
			createdById: $currentUserId,
			modifiedById: $currentUserId,
			visibility: Visibility::INVISIBLE,
		);
		$result = $this->documentTemplateRepository->add($template);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$document->templateId = $template->id;

		return new CreateTemplateResult($template);
	}

	private function makeTemplateFolderRelation(Item\Document\Template $template): CreateTemplateFolderRelationResult|Result
	{
		if (!$template->id)
		{
			return (new Main\Result())->addError(new Main\Error('Incorrect template id'));
		}
		$currentUserId = (int)CurrentUser::get()->getId();
		if (!$currentUserId)
		{
			return (new Main\Result())->addError(new Main\Error('User not found'));
		}

		$template = $this->documentTemplateRepository->getById($template?->id);
		if ($template === null)
		{
			return (new Main\Result())->addError(new Main\Error('Template not found'));
		}

		$templateFolderRelation = new TemplateFolderRelation(
			entityId: $template->id,
			entityType: Template\EntityType::TEMPLATE,
			createdById: $currentUserId,
			parentId: 0,
		);

		$result = $this->templateFolderRelationRepository->add($templateFolderRelation);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return new CreateTemplateFolderRelationResult($templateFolderRelation);
	}

	/**
	 * @param string $title
	 * @param Item\Blank $blank
	 * @param Document\Entity\Dummy|null $entity
	 *
	 * @return string
	 */
	public function makeDocumentTitle(string $title, Item\Blank $blank, ?Document\Entity\Dummy $entity): string
	{
		return $title !== ''
			? $title
			: $this->makeDocNameFromBlank($blank, $entity)
		;
	}

	public function getMyCompanyIdByDocument(Item\Document $document): ?int
	{
		if ($document->id === null)
		{
			return null;
		}

		$companyMember = $this->memberRepository->getCompanyMemberByDocument(
			$document->id,
		);

		return $companyMember?->entityId;
	}

	/**
	 * @return list<int, int> Document id to company id
	 */
	public function listMyCompanyIdsForDocuments(Item\DocumentCollection $documents): array
	{
		$companyIds = [];
		foreach ($documents as $document)
		{
			if ($document === null)
			{
				continue;
			}

			if ($document->id === null)
			{
				continue;
			}

			$companyId = $this->getMyCompanyIdByDocument($document);
			if ($companyId !== null)
			{
				$companyIds[$document->id] = $companyId;
			}
		}

		return $companyIds;
	}

	public function getLastCreatedEmployeeDocumentFromDocuments(int $creatorUserId, Item\DocumentCollection $documents): ?Item\Document
	{
		$documentIds = $documents->listIdsWithoutNull();
		if (empty($documentIds))
		{
			return null;
		}

		$lastDocument = $this->documentRepository->getByCreatedFromDocumentIdsAndInitiatedByTypeAndCreatedByIdOrderedByDateCreateDesc(
			$documentIds,
			Type\Document\InitiatedByType::EMPLOYEE,
			$creatorUserId,
		);

		return $lastDocument;
	}

	public function getDocumentEntity(Item\Document $document): ?Document\Entity\Dummy
	{
		return $this->documentEntityFactory->getByDocument($document);
	}

	public function getByTemplateId(int $templateId): ?Item\Document
	{
		if ($templateId < 1)
		{
			return null;
		}

		return $this->documentRepository->getByTemplateId($templateId);
	}

	public function getByTemplateIds(int ...$ids): Item\DocumentCollection
	{
		return $this->documentRepository->getByTemplateIds(...$ids);
	}

	private function fireDocumentUpdatedTimelineEvent(Item\Document $document): void
	{
		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return;
		}

		/**
		 * @see \CCrmOwnerType::SmartB2eDocument
		 * @see \CCrmOwnerType::SmartDocument
		 */
		$itemFactory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($document->entityTypeId);

		if (
			$itemFactory
			&& $item = $itemFactory->getItem($document->entityId)
		)
		{
			\Bitrix\Crm\Activity\Provider\SignB2eDocument::onDocumentUpdate($item->getId());
		}
	}

	private function isIntegrationDocumentSettingsAvailableForProvider(?string $providerCode): bool
	{
		return $providerCode && $providerCode !== ProviderCode::SES_RU;
	}

	public function hasMoreThan(int $count, string $scenarioType): bool
	{
		$scenarios = match ($scenarioType)
		{
			Type\DocumentScenario::SCENARIO_TYPE_B2B => Type\DocumentScenario::getB2BScenarios(),
			Type\DocumentScenario::SCENARIO_TYPE_B2E => Type\DocumentScenario::getB2EScenarios(),
			default => [],
		};

		return $this->documentRepository->countByScenarios($scenarios) > $count;
	}
}
