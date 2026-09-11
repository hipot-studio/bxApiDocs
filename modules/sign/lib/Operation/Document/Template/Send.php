<?php

namespace Bitrix\Sign\Operation\Document\Template;

use Bitrix\Main\Error;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Document\BindingCollection;
use Bitrix\Sign\Item\Document\Template;
use Bitrix\Sign\Item\Document\Config\DocumentBlankReplacementConfig;
use Bitrix\Sign\Item\Field;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Repository\BlockRepository;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\CreateDocumentResult;
use Bitrix\Sign\Result\Operation\Document\Template\SendResult;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Providers\MemberDynamicFieldInfoProvider;
use Bitrix\Sign\Service\Providers\ProfileProvider;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\Document\ExternalDateCreateSourceType;
use Bitrix\Sign\Type\Document\ExternalIdSourceType;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\Template\Status;
use Bitrix\Sign\Type\Template\Visibility;

final class Send implements Contract\Operation
{
	// The client binds a failed regional field by error code, not by message text (see
	// install/js/sign/v2/b2e/submit-document-info): the wording is translated, the code is not.
	// The code doubles as the phrase code, so one identifier describes the error on both sides.
	public const ERROR_CODE_EXTERNAL_ID_REQUIRED = 'SIGN_B2E_TEMPLATE_SEND_EXTERNAL_ID_REQUIRED';
	public const ERROR_CODE_EXTERNAL_DATE_REQUIRED = 'SIGN_B2E_TEMPLATE_SEND_EXTERNAL_DATE_REQUIRED';
	public const ERROR_CODE_EXTERNAL_DATE_INVALID = 'SIGN_B2E_TEMPLATE_SEND_EXTERNAL_DATE_INVALID';

	private readonly DocumentService $documentService;
	private readonly DocumentRepository $documentRepository;
	private readonly MemberRepository $memberRepository;
	private readonly MemberService $memberService;
	private readonly ProfileProvider $profileProvider;
	private readonly MemberDynamicFieldInfoProvider $dynamicFieldProvider;
	private readonly BlockRepository $blockRepository;

	/**
	 * @var list<array{name: string, value: string}>
	 */
	private array $validDynamicFields = [];

	/**
	 * @var list<array{name: string, value: string}>
	 */
	private array $validLocalFields = [];
	private ?BindingCollection $bindings = null;

	public function __construct(
		private readonly Template $template,
		private readonly int $responsibleUserId,
		private readonly array $fields = [],
		private readonly ?int $sendFromUserId = null,
		private readonly ?int $representativeUserId = null,
		private readonly ?MemberCollection $memberList = null,
		private readonly ?DocumentBlankReplacementConfig $blankReplacementConfig = null,
		private readonly ?string $externalId = null,
		private readonly ?string $externalDate = null,
		?DocumentService $documentService = null,
		?ProfileProvider $profileProvider = null,
		?MemberDynamicFieldInfoProvider $dynamicFieldProvider = null,
		?BlockRepository $blockRepository = null,
	)
	{
		$this->documentService = $documentService ?? Container::instance()->getDocumentService();
		$this->documentRepository = Container::instance()->getDocumentRepository();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->memberService = Container::instance()->getMemberService();
		$this->profileProvider = $profileProvider ?? Container::instance()->getServiceProfileProvider();
		$this->dynamicFieldProvider = $dynamicFieldProvider ?? Container::instance()->getMemberDynamicFieldProvider();
		$this->blockRepository = $blockRepository ?? Container::instance()->getBlockRepository();
	}

	public function launch(): Main\Result|SendResult
	{
		if ($this->template->id === null)
		{
			return Result::createByErrorData(message: 'Template is not saved');
		}

		if ($this->template->status !== Status::COMPLETED)
		{
			return Result::createByErrorData(message: 'Template is not completed');
		}

		if ($this->template->visibility === Visibility::INVISIBLE)
		{
			return Result::createByErrorData(message: 'Template is not visible');
		}

		if ($this->getSendFromUserId() < 1)
		{
			return Result::createByErrorData(message: 'Send from user is not set');
		}

		if ($this->responsibleUserId < 1)
		{
			return Result::createByErrorData(message: 'Responsible user id not set');
		}

		$document = $this->documentRepository->getByTemplateId($this->template->id);
		if ($document === null)
		{
			return Result::createByErrorData(message: 'Document not found');
		}

		if (!in_array($document?->initiatedByType, InitiatedByType::getAll(), true))
		{
			return Result::createByErrorData(message: 'Cant send document by template');
		}

		if ($document->initiatedByType === InitiatedByType::EMPLOYEE)
		{
			if ($this->sendFromUserId === null)
			{
				return Result::createByErrorData(message: 'Send from user id is not set');
			}

			$result = $this->validateFields($document);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$result = $this->validateRegionalFields($document);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		$createResult = (new CreateDocumentFromTemplate(
			template: $this->template,
			templateDocument: $document,
			createdByUserId: $this->responsibleUserId,
			bindings: $this->bindings,
			blankReplacementConfig: $this->blankReplacementConfig,
		))->launch();
		if (!$createResult instanceof CreateDocumentResult)
		{
			return $createResult;
		}

		$newDocument = $createResult->document;

		$result = $this->updateMembers($newDocument);
		if (!$result->isSuccess())
		{
			return $this->rollbackOnFailure($result, $newDocument);
		}

		$result = $this->fillFields($newDocument->id);
		if (!$result->isSuccess())
		{
			return $this->rollbackOnFailure($result, $newDocument);
		}

		$result = $this->applyRegionalFields($newDocument);
		if (!$result->isSuccess())
		{
			return $this->rollbackOnFailure($result, $newDocument);
		}

		$result = $this->configureAndStart($newDocument);
		if (!$result->isSuccess())
		{
			return $this->rollbackOnFailure($result, $newDocument);
		}

		$setSmartDocumentAssignedByIdResult = $this->setSmartDocumentAssignedById($newDocument);
		if (!$setSmartDocumentAssignedByIdResult->isSuccess())
		{
			return $this->rollbackOnFailure($setSmartDocumentAssignedByIdResult, $newDocument);
		}

		$employeeMember = $this->memberRepository->getByDocumentIdWithRole($newDocument->id, Role::SIGNER);
		if ($employeeMember === null)
		{
			$result = (new Result())->addError(new Error('Employee member not found'));
			return $this->rollbackOnFailure($result, $newDocument);
		}

		$assigneeMember = $this->memberRepository->getByDocumentIdWithRole($newDocument->id, Role::ASSIGNEE);
		if ($assigneeMember === null)
		{
			$result = (new Result())->addError(new Error('Assignee member not found'));
			return $this->rollbackOnFailure($result, $newDocument);
		}

		return new SendResult($newDocument, $employeeMember, $assigneeMember);
	}

	private function setSmartDocumentAssignedById(Document $document): Main\Result
	{
		$result = new Main\Result();
		$entity = $this->documentService->getDocumentEntity($document);
		if ($entity === null)
		{
			return $result->addError(new Error('Entity not found'));
		}

		$assignee = $this->memberService->getAssignee($document);
		if (!$assignee)
		{
			return $result->addError(new Error('Assignee not found'));
		}

		$assigneeUserId = $this->memberService->getUserIdForMember($assignee, $document);
		if ($assigneeUserId === null)
		{
			return $result->addError(new Error('Assignee user not found'));
		}

		if (!$entity->setAssignedById($assigneeUserId))
		{
			return $result->addError(new Error('Cannot set assignee user'));
		}

		if (!$entity->addObserver($assigneeUserId))
		{
			return $result->addError(new Error('Cannot add observer user'));
		}

		return $result;
	}

	private function updateMembers(Document $document): Main\Result
	{
		$operation = new SetupTemplateMembers(
			document: $document,
			sendFromUserId: $this->sendFromUserId,
			representativeUserId: $this->representativeUserId,
			memberList: $this->memberList,
		);

		return $operation->launch();
	}

	private function rollbackOnFailure(Main\Result $result, Document $document): Main\Result
	{
		if ($result->isSuccess() || $document->id === null)
		{
			return $result;
		}

		$rollbackResult = $this->documentService->rollbackDocument($document->id);
		if (!$rollbackResult->isSuccess())
		{
			$result->addErrors($rollbackResult->getErrors());
		}

		return $result;
	}

	private function configureAndStart(Document $newDocument): Main\Result
	{
		Container::instance()->getDocumentAgentService()->addConfigureAndStartAgent($newDocument->uid);

		return new Main\Result();
	}

	/**
	 * A regional field is mandatory for the employee exactly when the blank really contains the matching
	 * placeholder block: the same condition that makes the UI render the input (see
	 * Controllers\V1\B2e\Document\Template::getFieldsAction). The check runs on the template document before
	 * CreateDocumentFromTemplate, so invalid input never creates a document that has to be rolled back.
	 */
	private function validateRegionalFields(Document $templateDocument): Main\Result
	{
		$result = new Main\Result();
		$regionalBlockCodes = $this->getExistingRegionalBlockCodes($templateDocument->blankId);

		if (in_array(BlockCode::B2E_EXTERNAL_ID, $regionalBlockCodes, true) && $this->getExternalIdValue() === '')
		{
			return $result->addError($this->makeRegionalFieldError(self::ERROR_CODE_EXTERNAL_ID_REQUIRED));
		}

		if (!in_array(BlockCode::B2E_EXTERNAL_DATE_CREATE, $regionalBlockCodes, true))
		{
			return $result;
		}

		$externalDate = $this->getExternalDateValue();
		if ($externalDate === '')
		{
			return $result->addError($this->makeRegionalFieldError(self::ERROR_CODE_EXTERNAL_DATE_REQUIRED));
		}

		if (!$this->isValidManualExternalDate($externalDate))
		{
			return $result->addError($this->makeRegionalFieldError(self::ERROR_CODE_EXTERNAL_DATE_INVALID));
		}

		return $result;
	}

	private function makeRegionalFieldError(string $errorCode): Error
	{
		return new Error(Loc::getMessage($errorCode), $errorCode);
	}

	/**
	 * Persists the regional external fields (registration number, creation date) entered on the document
	 * creation step as MANUAL values, mirroring the company flow. The values are stored on the document
	 * before configureAndStart schedules the configure agent; when that agent later runs, it reads the
	 * trusted MANUAL value through FieldValue::getB2eRegionalFieldValue and substitutes the matching
	 * placeholder. A value is stored only when the created document really has the matching regional block,
	 * so that without such a block nothing is persisted (otherwise a stray externalId would leak into the
	 * smart-document title as "No <externalId>"). Employee input is already checked by validateRegionalFields;
	 * the date is re-checked here because the created document may carry a replaced blank.
	 * The company flow keeps using its own per-document change calls.
	 */
	private function applyRegionalFields(Document $newDocument): Main\Result
	{
		if ($newDocument->initiatedByType !== InitiatedByType::EMPLOYEE)
		{
			return new Main\Result();
		}

		$regionalBlockCodes = $this->getExistingRegionalBlockCodes($newDocument->blankId);

		$externalId = $this->getExternalIdValue();
		if ($externalId !== '' && in_array(BlockCode::B2E_EXTERNAL_ID, $regionalBlockCodes, true))
		{
			$result = $this->documentService->modifyExternalId(
				documentUid: $newDocument->uid,
				externalId: $externalId,
				sourceType: ExternalIdSourceType::MANUAL,
				hcmLinkSettingId: null,
			);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		$externalDate = $this->getExternalDateValue();
		if (
			$externalDate !== ''
			&& in_array(BlockCode::B2E_EXTERNAL_DATE_CREATE, $regionalBlockCodes, true)
			&& $this->isValidManualExternalDate($externalDate)
		)
		{
			$result = $this->documentService->modifyExternalDate(
				documentUid: $newDocument->uid,
				sourceType: ExternalDateCreateSourceType::MANUAL,
				externalDate: $externalDate,
				hcmLinkSettingId: null,
			);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Main\Result();
	}

	/**
	 * @return list<string>
	 */
	private function getExistingRegionalBlockCodes(?int $blankId): array
	{
		if ($blankId === null)
		{
			return [];
		}

		return $this->blockRepository->getExistingB2eRegionalBlockCodesByBlankId($blankId);
	}

	private function getExternalIdValue(): string
	{
		return trim((string)$this->externalId);
	}

	private function getExternalDateValue(): string
	{
		return trim((string)$this->externalDate);
	}

	private function isValidManualExternalDate(string $externalDate): bool
	{
		try
		{
			Main\Type\DateTime::createFromUserTime($externalDate);
		}
		catch (Main\ObjectException)
		{
			return false;
		}

		return true;
	}

	private function fillFields(int $documentId): Main\Result
	{
		$signer = $this->memberRepository->getByDocumentIdWithRole($documentId, Role::SIGNER);
		if (!$signer)
		{
			return (new Main\Result())->addError(new Main\Error('Signer not found in new document'));
		}

		$result = $this->saveValidDynamicFields($signer);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->saveValidLocalFields($signer);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return new Main\Result();
	}

	private function validateFields(Document $document): Main\Result
	{
		$allowedFieldMap = $this->getAllowedFieldsMap($document);
		$presentFieldsMap = [];
		foreach ($this->fields as $field)
		{
			$name = trim((string)($field['name'] ?? ''));
			$value = trim((string)($field['value'] ?? ''));
			$allowedField = $allowedFieldMap[$name] ?? null;
			if (!$allowedField instanceof Field)
			{
				return (new Result())->addError(new Main\Error("Unexpected field: $name"));
			}

			if ($allowedField->required !== false && $value === '')
			{
				return (new Result())->addError(new Main\Error("No value for required field: $name"));
			}

			['fieldCode' => $fieldCode] = NameHelper::parse($name);
			if ($this->profileProvider->isFieldCodeUserProfileField($fieldCode))
			{
				$this->validLocalFields[] = ['name' => $name, 'value' => $value];
			}
			elseif ($this->dynamicFieldProvider->isFieldCodeMemberDynamicField($fieldCode))
			{
				$this->validDynamicFields[] = ['name' => $name, 'value' => $value];
			}
			else
			{
				return (new Result())->addError(new Main\Error("Unexpected field: $name"));
			}

			$presentFieldsMap[$name] = $value;
		}

		foreach ($allowedFieldMap as $field)
		{
			$value = $presentFieldsMap[$field->name] ?? '';
			if ($field->required !== false && $value === '')
			{
				return (new Result())->addError(new Main\Error("No value for required field: $field->name"));
			}
		}

		return new Result();
	}

	/**
	 * @param Document $document
	 *
	 * @return array<string, Field>
	 */
	private function getAllowedFieldsMap(Document $document): array
	{
		if ($this->sendFromUserId === null)
		{
			return [];
		}

		return (new \Bitrix\Sign\Factory\Field())
			->createDocumentFutureSignerFields($document, $this->sendFromUserId)
			->getNameMap()
		;
	}

	private function saveValidLocalFields(Member $signer): Main\Result
	{
		if (!$this->validLocalFields)
		{
			return new Main\Result();
		}

		$operation = new Operation\Member\SaveFields(
			member: $signer,
			fields: $this->validLocalFields,
		);

		return $operation->launch();
	}

	private function saveValidDynamicFields(Member $signer): Main\Result
	{
		if (!$this->validDynamicFields)
		{
			return new Main\Result();
		}

		$operation = new Operation\FillFields(
			fields: $this->validDynamicFields,
			member: $signer,
		);

		return $operation->launch();
	}

	private function getSendFromUserId(): int
	{
		$userId = (int)$this->sendFromUserId;

		return $userId ?: $this->template->createdById;
	}

	public function setBindings(BindingCollection $bindings): void
	{
		$this->bindings = $bindings;
	}
}
