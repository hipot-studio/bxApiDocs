<?php

namespace Bitrix\Sign\Service\Integration\Bizproc;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Integration\Bitrix24\B2eTariff;
use Bitrix\Sign\Integration\CRM\Entity;
use Bitrix\Sign\Item;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Bizproc\Result\DraftCreationResult;
use Bitrix\Sign\Service\Sign\BlankService;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Type\Api\TransportErrorCode;
use Bitrix\Sign\Type\Bizproc\DraftActivityMessageCode;
use Bitrix\Sign\Type\BlankScenario;
use Bitrix\Sign\Type\Document\CreationSourceMetaKey;
use Bitrix\Sign\Type\Document\CreationSourceType;
use Bitrix\Sign\Type\Document\EntityType;
use Bitrix\Sign\Type\Document\InitiatedByType;

/**
 * Creates the draft of a B2E document the way the draft activity needs it: availability and tariff
 * first, then the idempotency key, then blank, registration, upload and the responsible of the smart
 * document. The flow reports a code and never a phrase - the phrases live in the lang file of the
 * activity.
 *
 * There is no transaction around the chain: it crosses the border of the signing service. A partial
 * failure is taken back by the compensation of this service instead.
 */
final class DocumentDraftCreator
{
	private const DOCX_EXTENSION = 'docx';

	private readonly DraftFileSourceResolver $fileSourceResolver;
	private readonly DraftIdempotencyService $idempotencyService;
	private readonly BlankService $blankService;
	private readonly DocumentService $documentService;
	private readonly DocumentRepository $documentRepository;

	/** @var \Closure(): bool */
	private readonly \Closure $b2eAvailabilityChecker;

	/** @var \Closure(): Main\Result */
	private readonly \Closure $licenseRestrictionChecker;

	/** @var \Closure(): bool */
	private readonly \Closure $tariffRestrictionChecker;

	/** @var \Closure(int, int, int): Main\Result */
	private readonly \Closure $responsibleAssigner;

	/** @var \Closure(Item\Document, int, int): Main\Result */
	private readonly \Closure $entityRelationLinker;

	/**
	 * Every collaborator is injectable and every default comes from the container or from the static
	 * API behind it. The checks of the availability, the assignment of the responsible and the
	 * relation of CRM stand behind singletons with a static cache or behind a final class, so without
	 * the closures neither a restricted tariff nor a refused relation is reproducible in a test.
	 *
	 * @param \Closure(): bool|null $b2eAvailabilityChecker
	 * @param \Closure(): Main\Result|null $licenseRestrictionChecker
	 * @param \Closure(): bool|null $tariffRestrictionChecker
	 * @param \Closure(int, int, int): Main\Result|null $responsibleAssigner
	 * @param \Closure(Item\Document, int, int): Main\Result|null $entityRelationLinker
	 */
	public function __construct(
		?DraftFileSourceResolver $fileSourceResolver = null,
		?DraftIdempotencyService $idempotencyService = null,
		?BlankService $blankService = null,
		?DocumentService $documentService = null,
		?DocumentRepository $documentRepository = null,
		?\Closure $b2eAvailabilityChecker = null,
		?\Closure $licenseRestrictionChecker = null,
		?\Closure $tariffRestrictionChecker = null,
		?\Closure $responsibleAssigner = null,
		?\Closure $entityRelationLinker = null,
	)
	{
		$container = Container::instance();

		$this->fileSourceResolver = $fileSourceResolver ?? $container->getDraftFileSourceResolver();
		$this->idempotencyService = $idempotencyService ?? $container->getDraftIdempotencyService();
		$this->blankService = $blankService ?? $container->getSignBlankService();
		$this->documentService = $documentService ?? $container->getDocumentService();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();

		$this->b2eAvailabilityChecker = $b2eAvailabilityChecker
			?? static fn(): bool => Storage::instance()->isB2eAvailable()
		;
		$this->licenseRestrictionChecker = $licenseRestrictionChecker
			?? static fn(): Main\Result => Container::instance()->getB2eTariffRestrictionService()->check()
		;
		$this->tariffRestrictionChecker = $tariffRestrictionChecker
			?? static fn(): bool => B2eTariff::instance()->isB2eRestrictedInCurrentTariff()
		;
		$this->responsibleAssigner = $responsibleAssigner
			?? static fn(int $entityTypeId, int $entityId, int $userId): Main\Result =>
				Entity::updateEntityResponsible($entityTypeId, $entityId, $userId)
		;
		$this->entityRelationLinker = $entityRelationLinker
			?? static fn(Item\Document $document, int $entityId, int $entityTypeId): Main\Result =>
				Container::instance()
					->getCrmEntityRelationService()
					->addRelationToSmartB2eDocument($document, $entityId, $entityTypeId)
		;
	}

	/**
	 * @param string|null $fileSource source code written by the canonical control of the platform
	 * @param mixed $filePairValue value of the pair, already resolved by the platform
	 * @param mixed $fileExpressionValue value of the standalone expression, already resolved by the platform
	 * @param int|null $responsibleUserId subject of the document; neither his rights nor his activity
	 *   are checked - he is assigned and does not act
	 * @param int $workflowTemplateId template of the automation, part of the idempotency key
	 * @param string $activityName system name of the activity in that template, part of the key
	 * @param string|null $workflowInstanceId act of the creation in the source, kept for the support
	 * @param int|null $sourceEntityTypeId type of the entity the process runs on
	 * @param int|null $sourceEntityId id of that entity
	 */
	public function create(
		?string $fileSource,
		mixed $filePairValue,
		mixed $fileExpressionValue,
		?int $responsibleUserId,
		int $workflowTemplateId,
		string $activityName,
		?string $workflowInstanceId = null,
		?int $sourceEntityTypeId = null,
		?int $sourceEntityId = null,
	): DraftCreationResult
	{
		// the availability and the tariff are checked before anything is created: otherwise every
		// refusal would have to take a blank back
		$availabilityError = $this->checkAvailability();
		if ($availabilityError !== null)
		{
			return self::failed($availabilityError);
		}

		$reusedDocument = $this->idempotencyService->findReusable(
			$sourceEntityTypeId,
			$sourceEntityId,
			$workflowTemplateId,
			$activityName,
		);
		if ($reusedDocument !== null)
		{
			// the flow stops here, so nothing of this run exists and the compensation has nothing to
			// take back: the document of the previous run is never touched
			return self::describe($reusedDocument, isReused: true);
		}

		$fileResult = $this->fileSourceResolver->resolve($fileSource, $filePairValue, $fileExpressionValue);
		if (!$fileResult->isSuccess())
		{
			return (new DraftCreationResult())->addErrors($fileResult->getErrors());
		}

		if (($responsibleUserId ?? 0) < 1)
		{
			return self::failed(self::error(DraftActivityMessageCode::RESPONSIBLE_NOT_SET));
		}

		$fileData = $fileResult->getData();

		// the reference to the source entity is a pair, so half of it is no reference at all: the
		// origin is then written without one, and neither the key nor the relation of CRM works
		$hasSourceEntity = ($sourceEntityTypeId ?? 0) > 0 && ($sourceEntityId ?? 0) > 0;

		return $this->createDraft(
			(int)$fileData['fileId'],
			(string)$fileData['extension'],
			$responsibleUserId,
			new Item\Document\CreationSource(
				type: CreationSourceType::BIZPROC,
				id: $workflowInstanceId,
				entityTypeId: $hasSourceEntity ? $sourceEntityTypeId : null,
				entityId: $hasSourceEntity ? $sourceEntityId : null,
				specifics: [
					CreationSourceMetaKey::BIZPROC_TEMPLATE_ID->value => $workflowTemplateId,
					CreationSourceMetaKey::BIZPROC_ACTIVITY_NAME->value => $activityName,
				],
			),
		);
	}

	private function createDraft(
		int $fileId,
		string $extension,
		int $responsibleUserId,
		Item\Document\CreationSource $creationSource,
	): DraftCreationResult
	{
		$blankResult = $this->blankService->createFromFileIds(
			files: [$fileId],
			scenario: BlankScenario::B2E,
			forTemplate: false,
			hasPlaceholders: mb_strtolower($extension) === self::DOCX_EXTENSION,
		);
		$blankId = (int)$blankResult->getId();
		if (!$blankResult->isSuccess() || $blankId < 1)
		{
			return self::failed(
				self::error(DraftActivityMessageCode::BLANK_CREATE_FAILED, self::firstMessageOf($blankResult)),
			);
		}

		// the permission check is switched off by the argument and not by the state of the service:
		// the service is a singleton of the hit, and a switched state would stay on for every other
		// caller of the same hit
		$registerResult = $this->documentService->register(
			blankId: $blankId,
			createdById: $responsibleUserId,
			entityType: EntityType::SMART_B2E,
			initiatedByType: InitiatedByType::COMPANY,
			creationSource: $creationSource,
			checkPermission: false,
		);

		// the registration reports the id of the document even when it fails halfway: the row is
		// already there, and only the signing service refused
		$registeredId = (int)($registerResult->getData()['documentId'] ?? 0);
		$document = $registeredId > 0 ? $this->documentRepository->getById($registeredId) : null;

		if (!$registerResult->isSuccess() || $document?->uid === null)
		{
			return $this->compensate(
				self::error(DraftActivityMessageCode::DOCUMENT_CREATE_FAILED, self::firstMessageOf($registerResult)),
				$document,
				$blankId,
			);
		}

		$uploadResult = $this->documentService->upload($document->uid);
		if (!$uploadResult->isSuccess())
		{
			return $this->compensate(
				self::error(DraftActivityMessageCode::DOCUMENT_UPLOAD_FAILED, self::firstMessageOf($uploadResult)),
				$document,
				$blankId,
			);
		}

		// the status of the row is what the process gets, so it is read back after the upload moved it
		$document = $this->documentRepository->getById($registeredId) ?? $document;

		$assignResult = ($this->responsibleAssigner)(
			(int)$document->entityTypeId,
			(int)$document->entityId,
			$responsibleUserId,
		);
		if (!$assignResult->isSuccess())
		{
			// the draft itself is whole and stays on the portal: the responsible is missing on its
			// card only, and the author of the process is told which card to fill in by hand
			return self::failed(new Error(
				'',
				DraftActivityMessageCode::RESPONSIBLE_ASSIGN_FAILED->value,
				(int)$document->entityId,
			));
		}

		$result = self::describe($document, isReused: false);
		$relationError = $this->linkSourceEntity($document, $creationSource);

		return $relationError === null ? $result : $result->addWarning($relationError);
	}

	/**
	 * The trio of the controller of documents: the B2E scenario of the portal, the restriction of the
	 * license on a self-hosted installation and the restriction of B2E in the current tariff. The
	 * refusals are told apart by the code of the error and never by its text: both refusals of the
	 * license check carry an empty message.
	 */
	private function checkAvailability(): ?Error
	{
		if (!($this->b2eAvailabilityChecker)())
		{
			return self::error(DraftActivityMessageCode::UNAVAILABLE);
		}

		$licenseResult = ($this->licenseRestrictionChecker)();
		if (!$licenseResult->isSuccess())
		{
			$licenseError = $licenseResult->getError();
			$code = match ($licenseError?->getCode())
			{
				'LICENSE_LIMITATIONS' => DraftActivityMessageCode::TARIFF_RESTRICTED,
				TransportErrorCode::CLIENT_CONNECTION_ERROR->value =>
					DraftActivityMessageCode::LICENSE_SERVICE_UNAVAILABLE,
				// a refusal of a foreign service without a code of the contract keeps its text: the
				// author of the process gets the reserve phrase with that text as a tail
				default => DraftActivityMessageCode::UNKNOWN_REASON,
			};

			return self::error($code, (string)$licenseError?->getMessage());
		}

		return ($this->tariffRestrictionChecker)()
			? self::error(DraftActivityMessageCode::TARIFF_RESTRICTED)
			: null
		;
	}

	/**
	 * Takes back what this very run has created. A blank without a document is rolled back, a
	 * registered document is deleted by the operation of the module - the one that takes the members
	 * and the blank with it. The card of the smart process is left alone: deleting it would pull the
	 * events of CRM and its own automation, so its id goes to the log of the process instead.
	 *
	 * @param Error $reason the failure that stopped the flow; the compensation never replaces it
	 */
	private function compensate(Error $reason, ?Item\Document $document, int $blankId): DraftCreationResult
	{
		$result = self::failed($reason);

		if ($document === null)
		{
			$rollbackResult = $this->blankService->rollbackById($blankId);

			return $rollbackResult->isSuccess()
				? $result
				: $result->addError(self::compensationFailure($rollbackResult))
			;
		}

		$smartDocumentId = (int)$document->entityId;
		$deleteResult = (new Operation\Document\Delete($document))->launch();

		return $deleteResult->isSuccess()
			? $result->addError(
				new Error('', DraftActivityMessageCode::COMPENSATION_DONE->value, $smartDocumentId),
			)
			: $result->addError(self::compensationFailure($deleteResult))
		;
	}

	/**
	 * The relation of CRM is a product link for the navigation and not the carrier of the idempotency
	 * key, so its failure is a warning and never cancels the creation. The document is passed as an
	 * object: the service needs it registered, with the id of the smart document and the type of a
	 * B2E document already filled.
	 */
	private function linkSourceEntity(
		Item\Document $document,
		Item\Document\CreationSource $creationSource,
	): ?Error
	{
		$entityTypeId = (int)$creationSource->entityTypeId;
		$entityId = (int)$creationSource->entityId;
		if ($entityTypeId < 1 || $entityId < 1)
		{
			// the process runs on no entity of CRM: there is nothing to link the document to
			return null;
		}

		if (!Loader::includeModule('crm'))
		{
			return self::error(DraftActivityMessageCode::RELATION_FAILED);
		}

		$relationResult = ($this->entityRelationLinker)($document, $entityId, $entityTypeId);

		// the text of the refusal is dropped on purpose: the phrase of the warning explains the
		// outcome to the author of the process, and a type relation that is not set up is not his news
		return $relationResult->isSuccess() ? null : self::error(DraftActivityMessageCode::RELATION_FAILED);
	}

	private static function describe(Item\Document $document, bool $isReused): DraftCreationResult
	{
		return new DraftCreationResult(
			documentId: $document->id,
			smartDocumentId: $document->entityId,
			documentStatus: $document->status,
			isReused: $isReused,
		);
	}

	private static function failed(Error $error): DraftCreationResult
	{
		return (new DraftCreationResult())->addError($error);
	}

	/**
	 * @param string $reason text of a foreign service, appended to the phrase of the code by the
	 *   activity; empty for a refusal of the module itself
	 */
	private static function error(DraftActivityMessageCode $code, string $reason = ''): Error
	{
		return new Error($reason, $code->value);
	}

	private static function compensationFailure(Main\Result $result): Error
	{
		return self::error(DraftActivityMessageCode::UNKNOWN_REASON, self::firstMessageOf($result));
	}

	private static function firstMessageOf(Main\Result $result): string
	{
		return trim((string)$result->getError()?->getMessage());
	}
}
