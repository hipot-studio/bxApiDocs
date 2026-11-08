<?php

namespace Bitrix\Sign\Controllers\V1;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\DocumentAnnulPermission;
use Bitrix\Sign\Attribute;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\FeatureResolver;
use Bitrix\Sign\Item;
use Bitrix\Sign\Integration\Bitrix24\B2eTariff;
use Bitrix\Sign\Item\Document\Template;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Result\CreateDocumentResult;
use Bitrix\Sign\Repository\BlankRepository;
use Bitrix\Sign\Service;
use Bitrix\Sign\Service\Sign\Document\Template\AccessService;
use Bitrix\Sign\Service\Sign\Document\TemplateService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Type\Access\AccessibleItemType;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Template\EntityType;

class Document extends Controller
{
	/**
	 * The dictionary of the settings dialog reads the tax id of every company it answers with, and a
	 * menu of more than that many companies is picked from by typing anyway.
	 */
	private const B2E_COMPANY_DICTIONARY_LIMIT = 50;

	private Service\Sign\DocumentService $documentService;
	private TemplateService $templateService;
	private AccessService $templateAccessService;
	private BlankRepository $blankRepository;

	public function configureActions(): array
	{
		$actions = parent::configureActions();
		foreach (['getPlaceholderValidationStatus', 'validatePlaceholders'] as $actionName)
		{
			$actions[$actionName]['+prefilters'][] = new \Bitrix\Main\Engine\ActionFilter\HttpMethod([
				\Bitrix\Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
			]);
		}

		return $actions;
	}

	public function __construct(Request $request = null)
	{
		parent::__construct($request);
		$this->documentService = Service\Container::instance()->getDocumentService();
		$this->templateService = Service\Container::instance()->getDocumentTemplateService();
		$this->templateAccessService = Service\Container::instance()->getTemplateAccessService();
		$this->blankRepository = Service\Container::instance()->getBlankRepository();
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_TEMPLATE_ADD),
	)]
	public function registerAction(
		int $blankId,
		?string $scenarioType = null,
		bool $asTemplate = false,
		int $chatId = 0,
		int $templateFolderId = 0,
		?string $initiatedByType = null,
	): array
	{
		$scenarioType ??= Type\DocumentScenario::SCENARIO_TYPE_B2B;

		if ($scenarioType === Type\DocumentScenario::SCENARIO_TYPE_B2E)
		{
			if (!Storage::instance()->isB2eAvailable())
			{
				$this->addError(new Error('Document scenario not available'));

				return [];
			}

			$result = Service\Container::instance()->getB2eTariffRestrictionService()->check();
			if (!$result->isSuccess())
			{
				Service\Container::instance()->getSignBlankService()->rollbackById($blankId);
				$this->addErrors($result->getErrors());

				return [];
			}

			if (B2eTariff::instance()->isB2eRestrictedInCurrentTariff())
			{
				Service\Container::instance()->getSignBlankService()->rollbackById($blankId);
				$this->addB2eTariffRestrictedError();

				return [];
			}
		}

		$createdById = (int)CurrentUser::get()->getId();
		if (!$this->validateBlankAccess($blankId, $createdById))
		{
			return [];
		}

		if (
			$asTemplate
			&& $templateFolderId !== 0
			&& !$this->templateAccessService->hasAccessToEditFolderById($templateFolderId)
		)
		{
			$this->addErrorByMessage('No access rights to edit target folder');

			return [];
		}

		$result = $this->documentService->register(
			blankId: $blankId,
			createdById: $createdById,
			entityType: Type\Document\EntityType::getByScenarioType($scenarioType),
			asTemplate: $asTemplate,
			initiatedByType: InitiatedByType::tryFrom($initiatedByType ?? '') ?? InitiatedByType::COMPANY,
			chatId: $chatId,
		);
		$resultData = $result->getData();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			if ($docId = ($resultData['documentId'] ?? null))
			{
				$this->documentService->rollbackDocument($docId);
			}
			return [];
		}
		$template = $resultData['template'] ?? null;
		if ($template !== null && !$template instanceof Template)
		{
			$this->addErrorByMessage('Template not found');

			return [];
		}

		$result = $this->templateService->updateParent($templateFolderId, [$template?->id], EntityType::TEMPLATE);
		if (!$result->isSuccess())
		{
			$this->addError(new Error('Update parent id error'));
			return [];
		}

		return [
			'uid' => $resultData['document']->uid,
			'templateUid' => $template?->uid,
			'templateId' => $template?->id,
		];
	}

	#[Attribute\ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function modifyInitiatedByTypeAction(
		string $uid,
		string $initiatedByType,
	): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->addError(new Error('Document scenario not available'));

			return [];
		}

		$initiatedByTypeValue = InitiatedByType::tryFrom($initiatedByType);

		if (null === $initiatedByTypeValue)
		{
			$this->addError(new Error('Initiator type is wrong'));

			return [];
		}

		$result = $this->documentService->modifyInitiatedByType($uid, $initiatedByTypeValue);

		if (!$result instanceof CreateDocumentResult)
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$document = $result->document;

		return [
			'uid' => $document->uid,
			'initiatedByType' => $document->initiatedByType,
		];
	}

	/**
	 * @param string $uid
	 * @param int $blankId
	 *
	 * @return array{uid: string}
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function changeBlankAction(
		string $uid,
		int $blankId,
		bool $copyBlocksFromPreviousBlank = false,
	): array
	{
		if (!$this->validateBlankAccess($blankId, (int)CurrentUser::get()->getId()))
		{
			return [];
		}

		$result = $this->documentService->changeBlankAndUpload(
			$uid,
			$blankId,
			$copyBlocksFromPreviousBlank,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$document = $result->getData()['document'] ?? null;

		if (!$document instanceof \Bitrix\Sign\Item\Document)
		{
			$this->addError(new Error('Cannot change document blank'));

			return [];
		}

		$this->precomputeRawPlaceholders($document);

		$template = Service\Container::instance()
			->getDocumentTemplateService()
			->getById((int)$document->templateId)
		;

		return [
			'uid' => $document->uid,
			'templateUid' => $template?->uid,
		];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid'
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid'
		)
	)]
	public function modifyDateSignUntilAction(string $uid,  ?int $dateSignUntilTs): array
	{
		$result = $this->documentService->modifyDateSignUntil($uid, DateTime::createFromTimestamp($dateSignUntilTs));

		if (!$result->isSuccess())
		{
			$this->addErrors(
				$this->container->getLocalizedErrorService()->localizeErrors(
					$result->getErrors()
				)
			);

			return [];
		}

		$document = $result->getData()['document'];

		return [
			'uid' => $document->uid,
			'dateSignUntil' => $document->dateSignUntil,
		];
	}

	/**
	 * @param string $uid
	 *
	 * @return array
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function uploadAction(
		string $uid,
	): array
	{
		$result = $this->documentService->upload($uid);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			$this->documentService->rollbackDocumentByUid($uid);
			return [];
		}

		$document = $result->getData()['document'] ?? null;
		if ($document instanceof Item\Document)
		{
			$this->precomputeRawPlaceholders($document);
		}

		return [];
	}

	/**
	 * Warms the raw placeholder code cache while the file has just reached the safe service, so that
	 * validating the inserted codes later needs no synchronous external call.
	 *
	 * The warmup itself is a second round trip to the safe service, and the answer of the action owes
	 * it nothing, so it runs after the hit is over: keeping it here would let a slow safe service hold
	 * the worker for the whole request timeout. A skipped warmup leaves validation fail-open for raw
	 * codes; it never adds an external call to the synchronous send path.
	 *
	 * Every action that replaces the body calls this, not the upload alone: the key holds the blank,
	 * and the online editor as well as a file replacement move the document to a new blank, so the
	 * entry of the previous body can never answer for the new one. Moving the call down into
	 * DocumentService::upload() would cover them all at once, but that is also what the mass paths
	 * call - copy, template import, fill and send, crm integration - and none of them wants a
	 * background job and an external call per created document.
	 */
	private function precomputeRawPlaceholders(Item\Document $document, ?Application $application = null): void
	{
		if (!Storage::instance()->isPlaceholderRestrictionEnabled())
		{
			return;
		}

		if ($document->uid === null || $document->id === null || $document->blankId === null)
		{
			return;
		}

		$documentUid = $document->uid;
		$documentId = $document->id;
		$blankId = $document->blankId;
		$groupId = $document->groupId ?? 0;
		($application ?? Application::getInstance())->addBackgroundJob(
			static function () use (
				$documentUid,
				$documentId,
				$blankId,
				$groupId,
			): void {
				Service\Container::instance()->getDocumentAgentService()->scheduleRawPlaceholderWarmup(
					$groupId,
					new Item\Document(
						id: $documentId,
						uid: $documentUid,
						blankId: $blankId,
						groupId: $groupId > 0 ? $groupId : null,
					),
				);
			},
		);
	}

	/**
	 * @param string $uid
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function loadAction(string $uid): array
	{
		$container = Service\Container::instance();
		$documentRepository = $container->getDocumentRepository();
		$document = $documentRepository->getByUid($uid);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		return (new \Bitrix\Sign\Ui\ViewModel\Wizard\Document($document))->toArray();
	}

	/**
	 * Reports the codes already inserted into the document that its signing scenario does not
	 * support, grouped by the reason. Informational only: nothing is blocked or removed.
	 *
	 * @return array{hasUnsupported: bool, reasons: array<string, list<string>>}
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
	)]
	public function validatePlaceholdersAction(string $uid): array
	{
		$document = Service\Container::instance()->getDocumentRepository()->getByUid($uid);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		$operation = new Operation\Placeholder\ValidateDocumentPlaceholders($document);
		$result = $operation->launch();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$unsupportedCodesByReason = $operation->getUnsupportedCodesByReason();

		return [
			'hasUnsupported' => $unsupportedCodesByReason !== [],
			'reasons' => $unsupportedCodesByReason,
		];
	}

	/**
	 * @return array{ready: bool}
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
	)]
	public function getPlaceholderValidationStatusAction(string $uid): array
	{
		$container = Service\Container::instance();
		$document = $container->getDocumentRepository()->getByUid($uid);
		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		if (
			!Storage::instance()->isPlaceholderRestrictionEnabled()
			|| $document->id === null
			|| $document->uid === null
			|| $document->blankId === null
		)
		{
			return ['ready' => true];
		}

		$cacheService = $container->getDocumentPlaceholderCacheService();
		if ($cacheService->getRawPlaceholders($document->id, $document->blankId) !== null)
		{
			return ['ready' => true];
		}

		$groupId = (int)$document->groupId;
		$agentService = $container->getDocumentAgentService();
		if (!$agentService->hasRawPlaceholderWarmupChainForDocument(
			$groupId,
			$document->uid,
			$document->id,
			$document->blankId,
		))
		{
			$agentService->scheduleRawPlaceholderWarmup($groupId, $document);
		}

		return ['ready' => false];
	}

	/**
	 * @param list<int> $templateIds
	 * @return array
	 */
	#[ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_ADD,
	)]
	public function loadByTemplateIdsAction(array $templateIds): array
	{
		$templateIds = array_filter(array_map(static fn(mixed $id): int => (int)$id, $templateIds));

		$templates = $this->templateService->getByIds($templateIds);
		if (!$this->templateAccessService->hasAccessToReadForCollection($templates))
		{
			$this->addError(new Error('No access rights to read templates in folder'));

			return [];
		}

		$documents = $this->documentService->getByTemplateIds(...$templateIds);

		$notFoundTemplates = array_diff($templateIds, array_keys($templates->mapWithIdKeys()));
		if (!empty($notFoundTemplates))
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENTS_NOT_FOUND')));

			return [];
		}

		return array_map(
			fn($document) => (new \Bitrix\Sign\Ui\ViewModel\Wizard\TemplateDocument($document))->toArray(),
			$documents->toArray()
		);
	}

	/**
	 * @param string $uid
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function getDocumentPreviewUrlAction(string $uid): array
	{
		$container = Service\Container::instance();
		$document = $container->getDocumentService()->getByUid($uid);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		$blankId = (int)$document->blankId;
		if ($blankId < 1)
		{
			$this->addError(new Error('Document blank id is empty'));

			return [];
		}

		return ['url' => $container->getSignBlankService()->getPreviewUrl($blankId)];
	}

	#[Attribute\ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function getEditUrlAction(string $uid): array
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId < 1)
		{
			$this->addError(new Error('User not found'));

			return [];
		}

		$result = $this->documentService->getEditUrl($uid, $userId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return [
			'editUrl' => $result->getData()['editUrl'],
			'diskFileId' => $result->getData()['diskFileId'],
		];
	}

	#[Attribute\ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function applyEditedFileAction(string $uid, int $diskFileId): array
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId < 1)
		{
			$this->addError(new Error('User not found'));

			return [];
		}

		$result = $this->documentService->applyEditedFile($uid, $diskFileId, $userId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$data = $result->getData();
		$document = $data['document'] ?? null;
		// The service falls back to the document of the previous body when the blank change gives none
		// back, and the codes of the new body would then be warmed under the key of the old one.
		if ($document instanceof Item\Document && $document->blankId === $data['blankId'])
		{
			$this->precomputeRawPlaceholders($document);
		}

		return [
			'blankId' => $data['blankId'],
			'hasPlaceholders' => $this->blankRepository->hasPlaceholdersById($data['blankId']),
		];
	}

	#[Attribute\ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function discardEditedFileAction(string $uid, int $diskFileId): array
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId < 1)
		{
			$this->addError(new Error('User not found'));

			return [];
		}

		$result = $this->documentService->discardEditedFile($diskFileId, $userId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return [];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'id',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'id',
		),
	)]
	public function loadByIdAction(int $id): array
	{
		$documentRepository = $this->container->getDocumentRepository();
		$document = $documentRepository->getById($id);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND')));
			return [];
		}

		return (new \Bitrix\Sign\Ui\ViewModel\Wizard\Document($document))->toArray();
	}

	/**
	 * @return array
	 */
	public function loadLanguageAction(): array
	{
		return Storage::instance()->getLanguages();
	}

	/**
	 * @param string $uid
	 * @param string $title
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function modifyTitleAction(
		string $uid,
		string $title,
	): array
	{
		$result = $this->documentService->modifyTitle($uid, trim($title));
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return [];
		}

		return [
			'blankTitle' => $result->getData()['blankTitle'] ?? '',
		];
	}

	/**
	 * @param string $uid
	 * @param string $langId
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function modifyLangIdAction(
		string $uid,
		string $langId,
	): array
	{
		$result = $this->documentService->modifyLangId($uid, $langId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return [];
	}

	/**
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function modifyInitiatorAction(
		string $uid,
		string $initiator,
	): array
	{
		$result = $this->documentService->modifyInitiator($uid, $initiator);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return [];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentUid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentUid',
		)
	)]
	public function refreshEntityNumberAction(string $documentUid): array
	{
		$document = $this->documentService->getByUid($documentUid);
		if ($document === null)
		{
			return [];
		}
		$result = $this->documentService->refreshEntityNumber($document);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return [];
		}

		return [];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function configureAction(string $uid): array
	{
		$result = (new Operation\ConfigureFillAndStart($uid))->launch();
		$this->addErrorsFromResult($result);
		if ($result instanceof Operation\Result\ConfigureResult && !$result->completed)
		{
			Service\Container::instance()
				->getDocumentAgentService()
				->addConfigureAndStartAgent($uid)
			;
		}

		return [];
	}

	/**
	 * Company dictionary for the B2E document settings dialog: the value stored in settings is the CRM company id.
	 */
	#[Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT)]
	public function listB2eCompaniesAction(): array
	{
		if (!Feature::instance()->isB2eBizprocChainEnabled())
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_B2E_COMPANIES_FEATURE_DISABLED'),
				'FEATURE_DISABLED',
			));

			return [];
		}

		if (B2eTariff::instance()->isB2eRestrictedInCurrentTariff())
		{
			$this->addB2eTariffRestrictedError();

			return [];
		}

		if (!Loader::includeModule('crm'))
		{
			$this->addError(new Error('Module crm not installed'));

			return [];
		}

		$myCompanyService = Service\Container::instance()->getCrmMyCompanyService();
		$showTaxId = !$myCompanyService->isTaxIdIsCompanyId();

		return [
			'showTaxId' => $showTaxId,
			'companies' => self::listSelectableCompanies(
				$myCompanyService->listWithTaxIds(
					checkRequisitePermissions: false,
					limit: self::B2E_COMPANY_DICTIONARY_LIMIT,
				),
				$showTaxId,
			),
		];
	}

	/**
	 * A company without a tax id is left out of the dictionary: the signing service knows a company by its
	 * tax id, so the settings run would refuse such a company anyway, while asking the service which
	 * companies are registered would cost the dialog an external request on every open.
	 *
	 * The tax id is answered only to a dialog that shows it: where it is not shown it repeats the
	 * company id the answer already carries, and the dictionary has no reason to send it.
	 *
	 * @return list<array{id: int, title: string, taxId?: non-empty-string}>
	 */
	private static function listSelectableCompanies(
		Item\Integration\Crm\MyCompanyCollection $myCompanies,
		bool $withTaxId,
	): array
	{
		$companies = [];
		foreach ($myCompanies as $company)
		{
			if ($company->id === null || trim((string)$company->taxId) === '')
			{
				continue;
			}

			$selectable = [
				'id' => $company->id,
				'title' => $company->name,
			];
			if ($withTaxId)
			{
				$selectable['taxId'] = $company->taxId;
			}

			$companies[] = $selectable;
		}

		return $companies;
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function modifyInviteMessageAction(string $uid, ?string $message = null): array
	{
		if (!Feature::instance()->isDocumentMessageEnabled())
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_MESSAGE_FEATURE_DISABLED'),
				'FEATURE_DISABLED',
			));

			return [];
		}

		$document = $this->documentService->getByUid($uid);
		if ($document === null)
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND'),
				'DOCUMENT_NOT_FOUND',
			));

			return [];
		}

		$result = $this->documentService->modifyInviteMessage($document, $message);
		if (!$result->isSuccess())
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		return ['success' => true];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'documentUid'
	)]
	public function modifyCompanyAction(string $documentUid, string $companyUid, int $companyEntityId): array
	{
		if (empty($companyUid) || $companyEntityId < 1)
		{
			$this->addError(new Error('Empty company'));

			return [];
		}

		$container = Service\Container::instance();
		$apiService = $container->getApiService();
		$result = $apiService->get('v1/b2e.company.provider.get', [
			'companyUid' => $companyUid,
		]);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$providerCode = $result->getData()['code'] ?? null;
		$result = $this->documentService->modifyCompany($documentUid, $companyUid, $companyEntityId);
		$this->addErrors($result->getErrors());
		if ($providerCode === null)
		{
			return [];
		}
		$document = $this->documentService->getByUid($documentUid);
		if ($document === null)
		{
			$this->addError(new Error('Document not found'));

			return [];
		}

		$convertedProviderCode = Type\ProviderCode::createFromProviderLikeString($providerCode);
		if ($convertedProviderCode === null)
		{
			$this->addErrorByMessage("Provider `$providerCode` is not valid");

			return [];
		}

		$result = $this->documentService->modifyProviderCode($document, $convertedProviderCode);
		$this->addErrors($result->getErrors());

		return [];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function modifyRegionDocumentTypeAction(string $uid, string $type): array
	{
		$result = $this->documentService->modifyRegionDocumentType($uid, $type);
		$this->addErrors($result->getErrors());

		return [];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function modifyExternalIdAction(
		string $uid,
		?string $id = null,
		?string $sourceType = null,
		?int $hcmLinkSettingId = null,
	): array
	{
		$sourceType ??= Type\Document\ExternalIdSourceType::MANUAL->value;
		$sourceType = Type\Document\ExternalIdSourceType::tryFrom($sourceType);
		if (!$sourceType)
		{
			$this->addError(new Error('Invalid sourceType'));
			return [];
		}

		$result = $this->documentService->modifyExternalId(
			documentUid: $uid,
			externalId: $id,
			sourceType: $sourceType,
			hcmLinkSettingId: $hcmLinkSettingId,
		);
		$this->addErrors($result->getErrors());

		return [];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function modifyHcmLinkDocumentTypeAction(
		string $uid,
		?int $hcmLinkSettingId = null,
	)
	{
		$result = $this->documentService->modifyHcmLinkDocumentType(
			documentUid: $uid,
			hcmLinkSettingId: $hcmLinkSettingId,
		);
		$this->addErrors($result->getErrors());

		return [];
	}

	private function validateBlankAccess(int $blankId, int $userId): bool
	{
		if ($userId < 1)
		{
			$this->addError(new Error('User not found'));

			return false;
		}

		$blank = $this->blankRepository->getById($blankId);
		if ($blank !== null && $blank->createdById === $userId)
		{
			return true;
		}

		if ($this->blankRepository->getByIdAndValidatePermissions($blankId) !== null)
		{
			return true;
		}

		$this->addError(new Error('Access denied'));

		return false;
	}

	public function isNotAcceptedAgreement(): bool
	{
		$agreementOptions = \CUserOptions::GetOption('sign', 'sign-agreement', null);
		$region = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion();
		return (
				!is_array($agreementOptions)
				|| !isset($agreementOptions['decision'])
				|| $agreementOptions['decision'] !== 'Y'
			)
			&& !in_array($region,
				[
					'ru',
					'by',
					'kz',
				],
				true,
			);
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function modifySchemeAction(string $uid, string $scheme): array
	{
		$result = $this->documentService->modifyScheme($uid, $scheme);
		$this->addErrors($result->getErrors());

		return [];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid',
	)]
	public function modifyExternalDateAction(
		string $uid,
		?string $externalDate,
		?string $sourceType = null,
		?int $hcmLinkSettingId = null,
	): array
	{
		$sourceType ??= Type\Document\ExternalDateCreateSourceType::MANUAL->value;
		$sourceType = Type\Document\ExternalDateCreateSourceType::tryFrom($sourceType);
		if (!$sourceType)
		{
			$this->addError(new Error('Invalid source type'));
			return [];
		}

		$result = $this->documentService->modifyExternalDate(
			documentUid: $uid,
			sourceType: $sourceType,
			externalDate: $externalDate,
			hcmLinkSettingId: $hcmLinkSettingId,
		);
		$this->addErrors($result->getErrors());

		return [];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_TEMPLATE_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
	)]
	public function modifyIntegrationIdAction(string $uid, ?int $integrationId = null): array
	{
		$result = $this->documentService->modifyHcmLinkCompanyId($uid, $integrationId);
		$this->addErrors($result->getErrors());

		return [];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_READ,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_READ,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'uid',
		)
	)]
	public function getFillAndStartProgressAction(string $uid): array
	{
		$result = (new Operation\GetFillAndStartProgress($uid))->launch();
		$this->addErrorsFromResult($result);
		if ($result instanceof Operation\Result\ConfigureProgressResult)
		{
			return [
				'completed' => $result->completed,
				'progress' => $result->progress,
			];
		}

		return [];
	}

	#[Attribute\ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'uid'
	)]
	public function removeAction(string $uid): array
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			$this->addError(new Error('B2e document scenario is not available'));

			return [];
		}

		$document = Service\Container::instance()->getDocumentRepository()->getByUid($uid);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		if ($document->id === null)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		if (!DocumentScenario::isB2EScenario($document->scenario))
		{
			$this->addError(new Error('Only b2e documents can be removed'));

			return [];
		}

		$expected = [Type\DocumentStatus::NEW, Type\DocumentStatus::UPLOADED];
		if (!in_array($document->status, $expected, true))
		{
			$this->addError(new Error(
				message: 'Document has improper status',
				code: 'SIGN_DOCUMENT_INCORRECT_STATUS',
				customData: [
					'has' => $document->status,
					'expected' => $expected,
				],
			));

			return [];
		}

		$rollbackResult = $this->documentService->rollbackDocument($document->id);

		if (!$rollbackResult->isSuccess())
		{
			$this->addErrors($rollbackResult->getErrors());

			return [];
		}

		return [];
	}

	/**
	 * @param list<int> $ids
	 *
	 * @return array{completed: bool, progress: int}
	 */
	#[Attribute\ActionAccess(
		permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'ids'
	)]
	public function getManyFillAndStartProgressAction(array $ids): array
	{
		if (empty($ids))
		{
			$this->addErrorByMessage('No ids');

			return [];
		}

		$documents = Service\Container::instance()->getDocumentRepository()->listByIds($ids);
		$notFoundDocuments = array_diff($ids, array_keys($documents->getArrayByIds()));
		if (!empty($notFoundDocuments))
		{
			$this->addErrorByMessage('Not found documents with ids: ' . implode(',', $notFoundDocuments));

			return [];
		}

		$completedCount = 0;
		foreach ($documents as $document)
		{
			$result = (new Operation\GetFillAndStartProgressByDocument($document))->launch();
			$this->addErrorsFromResult($result);
			if ($result instanceof Operation\Result\ConfigureProgressResult && $result->completed)
			{
				$completedCount++;
			}
		}

		$totalCount = $documents->count();
		$progress = $totalCount > 0 ? round(100 / $totalCount * $completedCount) : 0;

		return [
			'completed' => $totalCount === $completedCount,
			'progress' => $progress,
		];
	}

	/**
	 * Applies the annulment mark to every eligible signer member of one document,
	 * addressing the document by uid (used by the CRM card).
	 *
	 * The server resolves the annullable members itself (SIGNER role, DONE
	 * status), so `skipped` is 0 by construction and kept only for a uniform
	 * response shape. Per-record statuses are not returned; the card reloads.
	 *
	 * @return array{changed: int, unchanged: int, forbidden: int, skipped: int}
	 */
	#[Attribute\ActionAccess(ActionDictionary::ACTION_DOCUMENT_ANNUL)]
	public function annulByDocumentAction(string $uid, bool $annul): array
	{
		$counters = ['changed' => 0, 'unchanged' => 0, 'forbidden' => 0, 'skipped' => 0];

		if (!FeatureResolver::instance()->released('kedoDocumentAnnul'))
		{
			$this->addError(new Error('Document annulment is not available'));

			return $counters;
		}

		$container = Service\Container::instance();
		$document = $container->getDocumentRepository()->getByUid($uid);
		if ($document === null || $document->id === null)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_NOT_FOUND')));

			return $counters;
		}

		$userId = (int)CurrentUser::get()->getId();

		// Owner-scoped guard on the document (IDOR), decided before a single member is
		// read: a foreign document must not disclose through the counters how many of
		// its signers have completed signing.
		if (!$this->createAnnulPermission($userId)->canAnnulDocumentOwnedBy($document->createdById))
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLER_DOCUMENT_ANNUL_ACCESS_DENIED'),
				'ACCESS_DENIED',
			));

			return $counters;
		}

		$result = (new Operation\AnnulDocumentMembers($document, $annul, $userId))->launch();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return $counters;
		}

		return array_merge($counters, $result->getData());
	}

	protected function createAnnulPermission(int $userId): DocumentAnnulPermission
	{
		return DocumentAnnulPermission::forUser($userId);
	}
}
