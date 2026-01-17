<?php

namespace Bitrix\Sign\Operation\Document;

use Bitrix\Main;
use Bitrix\Main\IO\Path;
use Bitrix\Sign\Compatibility\Document\Scheme;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\FileRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\BlankService;
use Bitrix\Sign\Service\Api\B2e\ProviderCodeService;
use Bitrix\Sign\Service\Sign\DocumentAgentService;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\BlankScenario;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\ProviderCode;
use Bitrix\Sign\Type;
use Bitrix\Sign\Item;

final class FillAndSend implements Contract\Operation
{
	public Document $document;
	/** @var MemberCollection $members  */
	public MemberCollection $members;

	public function __construct(
		private readonly Document\Config\DocumentFillConfig $fillConfig,
		private readonly int $currentUserId,
		private ?BlankService $blankService = null,
		private ?ProviderCodeService $apiProviderCodeService = null,
		private ?DocumentRepository $documentRepository = null,
		private ?MemberRepository $memberRepository = null,
		private ?FileRepository $fileRepository = null,
		private ?DocumentService $documentService = null,
		private ?MemberService $memberService = null,
		private ?DocumentAgentService $documentAgentService = null,
	)
	{
		$this->blankService ??= Container::instance()->getSignBlankService();
		$this->apiProviderCodeService ??= Container::instance()->getApiProviderCodeService();
		$this->documentRepository ??= Container::instance()->getDocumentRepository();
		$this->memberRepository ??= Container::instance()->getMemberRepository();
		$this->fileRepository ??= Container::instance()->getFileRepository();
		$this->documentService ??= Container::instance()->getDocumentService();
		$this->memberService ??= Container::instance()->getMemberService();
		$this->documentAgentService ??= Container::instance()->getDocumentAgentService();
	}

	public function launch(): Main\Result
	{
		$validateConfigResult = $this->validateFillConfig();
		if (!$validateConfigResult->isSuccess())
		{
			return $validateConfigResult;
		}

		$config = $this->fillConfig;
		$providerCode = $this->apiProviderCodeService->loadProviderCode($config->companyProviderUid);
		if ($providerCode === null)
		{
			return Result::createByErrorData(message: 'Could not get provider information because provider ID is missing.');
		}
		$providerCode = ProviderCode::createFromProviderLikeString($providerCode);

		// Create file from source file
		$sourceFile = $config->sourceFiles[0];

		$fsFile = new Item\Fs\File(
			name: $sourceFile->name,
			type: $sourceFile->type,
			content: new Item\Fs\FileContent(data: base64_decode($sourceFile->content, true)),
		);

		$filePutResult = $this->fileRepository->put($fsFile);
		if (!$filePutResult->isSuccess())
		{
			return $filePutResult;
		}

		if ($fsFile->id === null)
		{
			return Result::createByErrorData(message: 'The "ID" field is empty in "File" item.');
		}

		//todo check max file size limit
		$blankService = $this->blankService;
		$createBlankResult = $blankService->createFromFileIds([$fsFile->id], BlankScenario::B2E);

		if (!$createBlankResult->isSuccess())
		{
			return Result::createByMainResult($createBlankResult);
		}

		$blankId = $createBlankResult->getId();
		$blank = Container::instance()->getBlankRepository()->getById($blankId);
		if ($blank === null)
		{
			return Result::createByErrorData(message: 'Could not create document.');
		}

		$createdById = $config->responsibleUser?->userId ?? $this->currentUserId;
		$createDocumentResult = $this->documentService->register(
			blankId: $blankId,
			createdById: $createdById,
			entityType: Type\Document\EntityType::getByScenarioType(Type\DocumentScenario::SCENARIO_TYPE_B2E),
			asTemplate: false,
			initiatedByType: InitiatedByType::COMPANY,
			chatId: 0,
		);

		$resultData = $createDocumentResult->getData();
		$documentUid = $resultData['document']->uid ?? null;

		if (!$createDocumentResult->isSuccess())
		{
			if ($docId = ($resultData['documentId'] ?? null))
			{
				$this->documentService->rollbackDocument($docId);
			}

			return $createDocumentResult;
		}

		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document)
		{
			return Result::createByErrorData(message: 'Document was not found.');
		}

		$entity = $this->documentService->getDocumentEntity($document);
		if ($entity === null)
		{
			return Result::createByErrorData(message: 'Could not create document.');
		}

		if (!$entity->setAssignedById($config->responsibleUser->userId))
		{
			return Result::createByErrorData(message: 'Cannot set responsible user');
		}

		if (!$entity->addObserver($config->responsibleUser->userId))
		{
			return Result::createByErrorData(message: 'Cannot add observer user');
		}

		$currentParty = 1;
		$members = new MemberCollection();

		if ($config->reviewerUser)
		{
			$members->add(
				new Member(
					party: $currentParty++,
					entityType: EntityType::USER,
					entityId: $config->reviewerUser->userId,
					role: Role::REVIEWER,
					employeeId: $config->reviewerUser->employeeId,
				),
			);
		}

		if ($config->editorUser)
		{
			$members->add(
				new Member(
					party: $currentParty++,
					entityType: EntityType::USER,
					entityId: $config->editorUser->userId,
					role: Role::EDITOR,
					employeeId: $config->editorUser->employeeId,
				),
			);
		}

		$assignee = $this->memberService->makeAssigneeByDocumentAndEntityId($document, $config->crmCompanyId);
		$representativeId = $config->representativeUser->userId;
		$assignee->party = $currentParty++;
		$members->add($assignee);

		/** @var Document\Config\DocumentMemberConfig $signerConfig */
		foreach ($config->signersList as $signerConfig)
		{
			$signer = $this->memberService->makeSignerByDocumentAndEntityId($document, $signerConfig->userId);
			$signer->employeeId = $signerConfig->employeeId;
			$signer->party = $currentParty++;
			$members->add($signer);
		}

		$setupMembersResult = $this->memberService->setupB2eMembers($documentUid, $members, $representativeId);
		if (!$setupMembersResult->isSuccess())
		{
			return $setupMembersResult;
		}

		$modifyRepresentativeIdResult = $this->documentService->modifyRepresentativeId($documentUid, $representativeId, $assignee->role);
		if (!$modifyRepresentativeIdResult->isSuccess())
		{
			// Revert adding members
			$this->memberService->cleanByDocumentUid($documentUid);

			return $modifyRepresentativeIdResult;
		}

		$modifyCompanyResult = $this->documentService->modifyCompany($documentUid, $config->companyProviderUid, $config->crmCompanyId);
		if (!$modifyCompanyResult->isSuccess())
		{
			return $modifyCompanyResult;
		}

		$document->hcmLinkCompanyId = $config->hcmLinkCompanyId;
		$document->providerCode = $providerCode;
		$document->regionDocumentType = $config->regionDocumentType;
		$document->scheme = Scheme::createDefaultSchemeByProviderCode($providerCode);

		$fillExternalSettingsResult = $this->fillDocumentExternalSettings($document);
		if (!$fillExternalSettingsResult->isSuccess())
		{
			return $fillExternalSettingsResult;
		}

		$updateResult = $this->documentRepository->update($document);
		if (!$updateResult->isSuccess())
		{
			return $updateResult;
		}

		$uploadDocumentResult = $this->documentService->upload($documentUid);
		if (!$uploadDocumentResult->isSuccess())
		{
			return $uploadDocumentResult;
		}

		$this->documentAgentService->addConfigureAndStartAgent($documentUid);

		$this->members = $this->memberRepository->listByDocumentId($document->getId());
		$this->document = $document;

		return new Main\Result();
	}

	private function fillDocumentExternalSettings(Document $document): Main\Result
	{
		$settings = $this->fillConfig->externalSettings;

		if ($settings?->externalId)
		{
			$externalIdSourceType = Type\Document\ExternalIdSourceType::MANUAL;
		}
		else
		{
			$externalIdSourceType = null;
		}

		if ($settings?->externalDateCreate)
		{
			$externalDateCreateSourceType = Type\Document\ExternalDateCreateSourceType::MANUAL;
		}
		else
		{
			$externalDateCreateSourceType = null;
		}

		$document->externalId = $settings?->externalId;
		$document->externalIdSourceType = $externalIdSourceType;
		$document->externalDateCreate = $settings?->externalDateCreate;
		$document->externalDateCreateSourceType = $externalDateCreateSourceType;

		return new Main\Result();
	}

	private function validateFillConfig(): Main\Result
	{
		$config = $this->fillConfig;

		// Validate signers
		if (empty($config->signersList))
		{
			return Result::createByErrorData(message: 'List of signing parties is empty.');
		}
		else
		{
			foreach ($config->signersList as $signerConfig)
			{
				if ($signerConfig->employeeId && !$signerConfig->userId)
				{
					return Result::createByErrorData(message: 'Could not find user for employee with code ' . $signerConfig->employeeId);
				}
			}
		}

		// Validate representative
		if (!$config->representativeUser)
		{
			return Result::createByErrorData(message: 'The "representative" field is missing.');
		}

		// Create file from source file
		$sourceFile = $config->sourceFiles[0];
		if (!Path::validateFilename($sourceFile->name))
		{
			return Result::createByErrorData(message: "Invalid file name.");
		}

		return new Main\Result();
	}

}
