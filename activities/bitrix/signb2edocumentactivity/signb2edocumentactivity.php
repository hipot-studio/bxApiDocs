<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\ActivityTable;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign;
use Bitrix\Sign\Item;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Document\Config\DocumentBlankReplacementConfig;
use Bitrix\Sign\Operation\Document\Template\Send;
use Bitrix\Sign\Result\Operation\Document\Template\SendResult;
use Bitrix\Sign\Service\Container;

/**
 * @property string[] $responsible
 * @property string[] $employee
 * @property string $employeeGroups
 * @property string[] $representative
 * @property string[] $reviewer
 * @property string[] $editor
 * @property int $templateId
 * @property mixed $file
 * @property string $message
 * @property string $isSubscriptionEnabled
 * @property int $documentId
 * @property string $documentStatus
 */
class CBPSignB2EDocumentActivity extends CBPActivity implements IBPEventActivity, IBPActivityExternalEventListener
{
	public const PARAM_TEMPLATE_ID = 'templateId';
	public const PARAM_FILE = 'file';
	public const PARAM_MESSAGE = 'message';
	public const PARAM_RESPONSIBLE = 'responsible';
	public const PARAM_EMPLOYEE = 'employee';
	public const PARAM_EMPLOYEE_GROUPS = 'employeeGroups';
	public const PARAM_REPRESENTATIVE = 'representative';
	public const PARAM_REVIEWER = 'reviewer';
	public const PARAM_EDITOR = 'editor';
	public const PARAM_IS_SUBSCRIPTION_ENABLED = 'isSubscriptionEnabled';
	public const RETURN_PARAM_DOCUMENT_ID = 'documentId';
	public const RETURN_PARAM_DOCUMENT_STATUS = 'documentStatus';
	public const RETURN_STATUS_SUCCESS = 'SUCCESS';
	public const RETURN_STATUS_FAIL = 'FAIL';
	private const EVENT_MODULE_NAME = 'sign';
	private const EMPLOYEE_GROUP_QUERY_BATCH_SIZE = 500;
	private const EMPLOYEE_GROUP_SELECTOR_LIMIT = 50;
	private const EMPLOYEE_LIMIT = 1000;

	private bool $isInEventActivityMode = false;
	private int $createdDocumentId = 0;
	private int $smartDocumentEntityId = 0;
	private int $smartDocumentEntityType = 0;
	private int $smartDocumentCreatedById = 0;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->setPropertiesTypes([
			self::PARAM_TEMPLATE_ID => [
				'Type' => FieldType::INT,
			],
			self::PARAM_FILE => [
				'Type' => FieldType::FILE,
				'Multiple' => false,
			],
			self::PARAM_MESSAGE => [
				'Type' => FieldType::TEXT,
				'Multiple' => false,
			],
			self::PARAM_RESPONSIBLE => [
				'Type' => FieldType::USER,
			],
			self::PARAM_EMPLOYEE => [
				'Type' => FieldType::USER,
				'Multiple' => true,
			],
			self::PARAM_EMPLOYEE_GROUPS => [
				'Type' => FieldType::STRING,
				'Required' => false,
			],
			self::PARAM_REPRESENTATIVE => [
				'Type' => FieldType::USER,
			],
			self::PARAM_REVIEWER => [
				'Type' => FieldType::USER,
				'Multiple' => true,
			],
			self::PARAM_EDITOR => [
				'Type' => FieldType::USER,
			],
			self::PARAM_IS_SUBSCRIPTION_ENABLED => [
				'Type' => FieldType::BOOL,
			],
			//return
			self::RETURN_PARAM_DOCUMENT_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_DOCUMENT_STATUS => [
				'Type' => FieldType::STRING,
			],
		]);

		$this->arProperties = [
			'Title' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ROBOT_TITLE'),
			self::PARAM_RESPONSIBLE => null,
			self::PARAM_EMPLOYEE => null,
			self::PARAM_EMPLOYEE_GROUPS => null,
			self::PARAM_REPRESENTATIVE => null,
			self::PARAM_REVIEWER => null,
			self::PARAM_EDITOR => null,
			self::PARAM_TEMPLATE_ID => null,
			self::PARAM_FILE => null,
			self::PARAM_MESSAGE => null,
			self::PARAM_IS_SUBSCRIPTION_ENABLED => 'N',
			//return
			self::RETURN_PARAM_DOCUMENT_ID => null,
			self::RETURN_PARAM_DOCUMENT_STATUS => null,
		];
	}

	protected function reInitialize(): void
	{
		parent::reInitialize();

		$this->{self::RETURN_PARAM_DOCUMENT_ID} = null;
		$this->{self::RETURN_PARAM_DOCUMENT_STATUS} = null;
	}

	/**
	 * @throws Main\LoaderException
	 */
	public static function isAvailable(): bool
	{
		if (!Main\Loader::includeModule('sign'))
		{
			return false;
		}

		if (!Main\Loader::includeModule('crm'))
		{
			return false;
		}

		if (!Sign\Config\Storage::instance()->isB2eAvailable())
		{
			return false;
		}

		return true;
	}

    /**
     * @throws Main\LoaderException
     */
    public function execute(): int
    {
		if ($this->isInEventActivityMode)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		if (!static::isAvailable())
		{
			$this->trackError(
                Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_UNAVAILABLE'),
            );

            return CBPActivityExecutionStatus::Closed;
		}

		$updateSmartDocumentEntityIdAndTypeResult = $this->updateSmartDocumentEntityIdAndType();

		if (!$updateSmartDocumentEntityIdAndTypeResult->isSuccess())
		{
			foreach ($updateSmartDocumentEntityIdAndTypeResult->getErrors() as $error)
			{
				$this->trackError(
					$error->getMessage(),
				);
			}

			return CBPActivityExecutionStatus::Closed;
		}

		$template = Container::instance()
			->getDocumentTemplateService()
			->getCompletedAndVisibleCompanyTemplateByUid($this->templateId)
		;

		if ($template === null)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_TEMPLATE_NOT_FOUND'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$document = Container::instance()->getDocumentService()->getByTemplateId((int)$template->id);
		if ($document === null)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_TEMPLATE_NOT_FOUND'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$representativeUserId = null;
		$companyId = null;

		$rawFileValue = $this->getRawProperty(self::PARAM_FILE);
		$parsedFileValue = $this->ParseValue($rawFileValue, 'file');
		$fileId = self::normalizeFileValueToId($parsedFileValue);
		if (!empty($parsedFileValue) && $fileId < 1)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_INVALID_FILE_ID'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$inviteMessage = $this->getParsedInviteMessage();
		if ($inviteMessage !== null)
		{
			$validateMessageResult = (new Sign\Operation\Document\Validation\ValidateInviteMessage($inviteMessage))->launch();
			if (!$validateMessageResult->isSuccess())
			{
				foreach ($validateMessageResult->getErrors() as $error)
				{
					$this->trackError($error->getMessage());
				}

				return CBPActivityExecutionStatus::Closed;
			}
		}

		$blankReplacementConfig = null;
		if ($fileId > 0)
		{
			$blankReplacementConfig = new DocumentBlankReplacementConfig(
				blankReplacementFileId: $fileId,
			);
		}

		$docId = $this->getDocumentId();
		$representativeRoleName = $this->getRoleName($this->representative);
		$editorRoleName = $this->getRoleName($this->editor);

		if ($representativeRoleName === null)
		{
			$representativeUserId = CBPHelper::extractFirstUser($this->representative, $docId);
		}

		if ((int)$representativeUserId > 0 || $representativeRoleName !== null)
		{
			$companyId = Container::instance()->getDocumentService()->getMyCompanyIdByDocument($document);
		}

		$editorUserId = $editorRoleName === null ? CBPHelper::extractFirstUser($this->editor, $docId) : null;

		$signerUserIdList = CBPHelper::extractUsers($this->employee, $docId);
		$employeeGroups = $this->getEmployeeGroups();
		if ($employeeGroups === null)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_MEMBERS_NOT_SET'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$resolveSignerUserIdsResult = self::resolveSignerUserIds($signerUserIdList, $employeeGroups);
		if (!$resolveSignerUserIdsResult->isSuccess())
		{
			foreach ($resolveSignerUserIdsResult->getErrors() as $error)
			{
				$this->trackError($error->getMessage());
			}

			return CBPActivityExecutionStatus::Closed;
		}
		$signerUserIdList = $resolveSignerUserIdsResult->getData()['userIds'] ?? [];

        $prepareMemberListForTemplateSendOperationResult = (new Sign\Operation\Document\Template\PrepareMemberListForTemplateSend(
            signerUserIdList: $signerUserIdList,
			companyId: $companyId,
			initiatedByType: $document->initiatedByType,
			reviewerUserList: $this->getValueList($this->reviewer, $docId),
			editorUserId: $editorUserId,
			representativeRoleName: $representativeRoleName,
			editorRoleName: $editorRoleName,
        ))->launch();

        if (!$prepareMemberListForTemplateSendOperationResult instanceof Sign\Result\Operation\Document\Template\PrepareMemberListForTemplateSendResult)
        {
            $this->trackError(
                Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_MEMBERS_NOT_SET'),
            );

			foreach ($prepareMemberListForTemplateSendOperationResult->getErrors() as $error)
			{
				$this->trackError(
					$error->getMessage(),
				);
			}

            return CBPActivityExecutionStatus::Closed;
        }

		if ($prepareMemberListForTemplateSendOperationResult->representativeRoleId !== null)
		{
			$document->representativeId = $prepareMemberListForTemplateSendOperationResult->representativeRoleId;
			$updateResult = Container::instance()->getDocumentService()->update($document);
			if (!$updateResult->isSuccess())
			{
				$this->trackError(
					Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_UPDATE_FAILED'),
				);

				return CBPActivityExecutionStatus::Closed;
			}
		}

		$sendFromUserId = null;
		if ($document->initiatedByType === Sign\Type\Document\InitiatedByType::EMPLOYEE)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_INVALID_TEMPLATE_TYPE'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$responsibleUserId = CBPHelper::extractFirstUser($this->responsible, $docId);
		if ($responsibleUserId === null)
		{
			$memberService = Container::instance()->getMemberService();
			$assignee = $memberService->getAssignee($document);
			$responsibleUserId = $memberService->getUserIdForMember($assignee) ?? $this->smartDocumentCreatedById;
		}

		if ((int)$responsibleUserId < 1)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_RESPONSIBLE_NOT_SET'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$sendOperation = new Send(
			template: $template,
			responsibleUserId: $responsibleUserId,
			sendFromUserId: $sendFromUserId,
			representativeUserId: $representativeUserId,
			memberList: $prepareMemberListForTemplateSendOperationResult->members,
			blankReplacementConfig: $blankReplacementConfig,
			inviteMessage: $inviteMessage,
		);

		$bindingCollection = Document\BindingCollection::emptyList();
		$bindingCollection->add(new Document\Binding($this->smartDocumentEntityId, $this->smartDocumentEntityType));
		$sendOperation->setBindings($bindingCollection);

		$sendOperationResult = $sendOperation->launch();

		if (!$sendOperationResult instanceof SendResult)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_UNABLE_TO_CREATE_DOC'),
			);

			foreach ($sendOperationResult->getErrors() as $error)
			{
				$this->trackError(
					$error->getMessage(),
				);
			}

			return CBPActivityExecutionStatus::Closed;
		}

		$newDocumentEntityTypeId = (int)$sendOperationResult->newDocument->entityTypeId;
		if ($newDocumentEntityTypeId === \CCrmOwnerType::Undefined)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_INVALID_SMART_DOCUMENT_ENTITY_TYPE'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$newDocumentEntityId = (int)$sendOperationResult->newDocument->entityId;
		if ($newDocumentEntityId < 1)
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_INVALID_SMART_DOCUMENT_ENTITY_ID'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$result = Sign\Integration\CRM\Entity::updateEntityResponsible(
			$newDocumentEntityTypeId,
			$newDocumentEntityId,
			$responsibleUserId
			);
		if (!$result->isSuccess())
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_RESPONSIBLE_FOR_SMART_DOCUMENT_NOT_SET'),
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$addCrmActivityResult = $this->addCrmActivity($sendOperationResult->newDocument);

		if (!$addCrmActivityResult->isSuccess())
		{
			$this->trackError(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_UNABLE_TO_CREATE_DOC_ACTIVITY'),
			);

			foreach ($addCrmActivityResult->getErrors() as $error)
			{
				$this->trackError(
					$error->getMessage(),
				);
			}

			return CBPActivityExecutionStatus::Closed;
		}

		if ($this->isSubscriptionEnabled() === false)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$this->createdDocumentId = (int)$sendOperationResult->newDocument->id;

		if ($this->createdDocumentId < 1)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$this->Subscribe($this);
		$this->isInEventActivityMode = false;

		return CBPActivityExecutionStatus::Executing;
	}

	/**
	 * @return string[]|int[]
	 */
	private function getValueList(array $values, array $docId): array
	{
		$result = [];
		foreach ($values as $value)
		{
			if ($value === null)
			{
				continue;
			}

			$currentValue = [$value];
			$roleName = $this->getRoleName($currentValue);
			if ($roleName)
			{
				$result[] = $roleName;

				continue;
			}

			$userId = CBPHelper::extractFirstUser($currentValue, $docId);
			if ($userId === null)
			{
				continue;
			}

			$result[] = $userId;
		}

		return $result;
	}

	private function getRoleName(?array $property): ?string
	{
		$value = $property[0] ?? '';

		if (self::getStructureNodeService()->getRoleIdByName($value) !== null)
		{
			return $value;
		}

		return null;
	}

	public static function validateProperties($properties = [], CBPWorkflowTemplateUser $user = null): array
    {
		$errors = [];
		if (empty($properties[self::PARAM_RESPONSIBLE]))
		{
			$errors[] = [
				"code" => "NotExist",
				"parameter" => self::PARAM_RESPONSIBLE,
				"message" => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_RESPONSIBLE_NOT_SET'),
			];
		}
		$employeeGroups = self::decodeEmployeeGroups($properties[self::PARAM_EMPLOYEE_GROUPS] ?? null);
		if ($employeeGroups === null)
		{
			$errors[] = self::getEmployeePreparationError();
		}
		elseif (empty($properties[self::PARAM_EMPLOYEE]) && empty($employeeGroups))
		{
			$errors[] = [
				"code" => "NotExist",
				"parameter" => self::PARAM_EMPLOYEE,
				"message" => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_EMPLOYEE_NOT_SET'),
			];
		}

		if (empty($properties[self::PARAM_TEMPLATE_ID]))
		{
			$errors[] = [
				"code" => "NotExist",
				"parameter" => self::PARAM_TEMPLATE_ID,
				"message" => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_EMPTY_TEMPLATE_ID'),
			];
		}

		$maxReviewersCount = (\Bitrix\Main\Loader::includeModule('sign') && class_exists(\Bitrix\Sign\Service\Sign\MemberService::class))
			? \Bitrix\Sign\Service\Sign\MemberService::MAX_REVIEWERS_COUNT
			: 20
		;

		if (count($properties[self::PARAM_REVIEWER] ?? []) > $maxReviewersCount)
		{
			$errors[] = [
				"code" => "MaxReviewerLimitExceeded",
				"parameter" => self::PARAM_TEMPLATE_ID,
				"message" => Loc::getMessage(
					'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_MAX_REVIEWER_LIMIT_EXCEEDED',
					['#LIMIT#' => $maxReviewersCount],
				),
			];
		}

		if (!empty($properties[self::PARAM_FILE]))
		{
			$fileValue = $properties[self::PARAM_FILE];
			$isExpression = is_string($fileValue) && CBPDocument::isExpression($fileValue);
			$isValidFileId = is_numeric($fileValue) && (int)$fileValue >= 1;
			if (!$isExpression && !$isValidFileId)
			{
				$errors[] = [
					"code" => "InvalidFileValue",
					"parameter" => self::PARAM_FILE,
					"message" => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_INVALID_FILE_ID'),
				];
			}
		}

		return array_merge($errors, parent::validateProperties($properties, $user));
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = "",
		$popupWindow = null,
		$siteId = '',
	): PropertiesDialog|string
	{
		if (!CModule::IncludeModule('crm'))
		{
			return '';
		}

		return (new PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]))
			->setMap(static::getPropertiesMap($documentType))
			->setRuntimeData([
				'employeeText' => is_array($arCurrentValues)
					? $arCurrentValues[self::PARAM_EMPLOYEE] ?? ''
					: null,
			])
		;
	}

	/**
	 * @return array<string,array{title: string, document_uid: string}>
	 */
	protected static function getTemplateSelectOptions(): array
	{
		$container = Container::instance();
		$templates = $container->getDocumentTemplateRepository()->getCompletedAndVisibleCompanyTemplateList();
		$documents = $container->getDocumentRepository()
			->listByTemplateIds($templates->getIdsWithoutNull())
			->sortByTemplateIdsDesc()
		;
		$result = [];

		foreach ($documents as $document)
		{
			$template = $templates->findById($document->templateId);
			if ($template === null)
			{
				continue;
			}

			$result[$template->uid] = [
				'title' => $template->title,
				'document_uid' => $document->uid,
				'responsibleSelectorValue' => self::getSelectorCurrentValue($document, Role::ASSIGNEE),
				'assigneeSelectorValue' => self::getSelectorCurrentValue($document, Role::ASSIGNEE),
				'reviewerSelectorValue' => self::getSelectorCurrentValues($document, Role::REVIEWER),
				'editorSelectorValue' => self::getSelectorCurrentValue($document, Role::EDITOR),
			];
		}

		return $result;
	}

	private static function getSelectorCurrentValue(Item\Document $document, string $role): ?string
	{
		if ($document->id === null || !in_array($role, Role::getAll(), true))
		{
			return null;
		}

		$container = Container::instance();
		$memberService = $container->getMemberService();

		$member = $memberService->getByDocumentIdWithRole($document->id, $role);
		if ($member === null)
		{
			return null;
		}

		return $member->entityType === Sign\Type\Member\EntityType::ROLE
			? self::getSignRoleNameForMember($member, $document)
			: (string)$memberService->getUserIdForMember($member);
	}

	/**
	 * @return string[]|int[]
	 */
	private static function getSelectorCurrentValues(Item\Document $document, string $role): array
	{
		if ($document->id === null)
		{
			return [];
		}

		if (!in_array($role, Role::getAll(), true))
		{
			return [];
		}

		$container = Container::instance();
		$memberService = $container->getMemberService();

		$result = [];
		$members = $memberService->listByDocumentIdWithRole($document, $role);
		foreach ($members as $member)
		{
			if ($member === null)
			{
				continue;
			}

			$resultValue = $member->entityType === Sign\Type\Member\EntityType::ROLE
				? self::getSignRoleNameForMember($member, $document)
				: (string)$memberService->getUserIdForMember($member);

			if ($resultValue === null)
			{
				continue;
			}

			$result[] = $resultValue;
		}

		return $result;
	}

	private static function getSignRoleNameForMember(Item\Member $member, Item\Document $document): ?string
	{
		$roleId = (int)($member->role === Role::ASSIGNEE ? $document->representativeId : $member->entityId);

		return self::getStructureNodeService()->getRoleNameById($roleId);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$messageProperty = self::isDocumentMessageEnabled()
			? [
				self::PARAM_MESSAGE => [
					'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_MESSAGE'),
					'Hint' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_MESSAGE_HINT'),
					'FieldName' => self::PARAM_MESSAGE,
					'Type' => FieldType::TEXT,
					'Multiple' => false,
					'Required' => false,
				],
			]
			: []
		;

		return [
			self::PARAM_TEMPLATE_ID => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_TEMPLATE_ID'),
				'FieldName' => self::PARAM_TEMPLATE_ID,
				'Type' => FieldType::SELECT,
				'Options' => [], // gets content with AJAX
				'Required' => true,
			],
			self::PARAM_FILE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_FILE'),
				'Hint' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_FILE_HINT'),
				'FieldName' => self::PARAM_FILE,
				'Type' => FieldType::FILE,
				'Multiple' => false,
				'Required' => false,
			],
			self::PARAM_RESPONSIBLE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_RESPONSIBLE'),
				'FieldName' => self::PARAM_RESPONSIBLE,
				'Type' => FieldType::USER,
				'Required' => true,
			],
			self::PARAM_EMPLOYEE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_EMPLOYEE'),
				'FieldName' => self::PARAM_EMPLOYEE,
				'Type' => FieldType::USER,
				'Required' => true,
				'Multiple' => true,
			],
			self::PARAM_EMPLOYEE_GROUPS => [
				'Name' => '',
				'FieldName' => self::PARAM_EMPLOYEE_GROUPS,
				'Type' => FieldType::STRING,
				'Required' => false,
				'AllowSelection' => false,
				'Hidden' => true,
			],
			self::PARAM_REPRESENTATIVE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_REPRESENTATIVE'),
				'FieldName' => self::PARAM_REPRESENTATIVE,
				'Type' => FieldType::USER,
				'Required' => false,
				'Settings' => self::getSignRoleFields(),
			],
			self::PARAM_REVIEWER => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_REVIEWER'),
				'FieldName' => self::PARAM_REVIEWER,
				'Type' => FieldType::USER,
				'Required' => false,
				'Settings' => self::getSignRoleFields(),
				'Multiple' => true,
			],
			self::PARAM_EDITOR => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_EDITOR'),
				'FieldName' => self::PARAM_EDITOR,
				'Type' => FieldType::USER,
				'Required' => false,
				'Settings' => self::getSignRoleFields(),
			],
			...$messageProperty,
			self::PARAM_IS_SUBSCRIPTION_ENABLED => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_IS_SUBSCRIPTION_ENABLED'),
				'FieldName' => self::PARAM_IS_SUBSCRIPTION_ENABLED,
				'Multiple' => false,
				'Type' => FieldType::BOOL,
				'Required' => false,
			],
		];
	}

	/**
	 * Expands business-process variables in the message with the standard bizproc mechanism, exactly
	 * as the file property is resolved. The length is validated on the expanded result, because a short
	 * template can produce a long text.
	 */
	private function getParsedInviteMessage(): ?string
	{
		if (!self::isDocumentMessageEnabled())
		{
			return null;
		}

		$rawValue = $this->getRawProperty(self::PARAM_MESSAGE);
		if (!is_string($rawValue) || $rawValue === '')
		{
			return null;
		}

		$parsedValue = $this->ParseValue($rawValue, FieldType::TEXT);
		if (!is_string($parsedValue))
		{
			return null;
		}

		$parsedValue = trim($parsedValue);

		return $parsedValue === '' ? null : $parsedValue;
	}

	private static function isDocumentMessageEnabled(): bool
	{
		return Main\Loader::includeModule('sign')
			&& Sign\Config\Feature::instance()->isDocumentMessageEnabled()
		;
	}

	private static function getSignRoleFields(): array
	{
		$result = [
			'additionalFields' => [],
		];
		foreach (self::getStructureNodeService()->getRoleList() as $roleName => $roleId)
		{
			$result['additionalFields'][] = [
				'id' => $roleName,
				'name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ROBOT_ROLE_' . $roleName . '_TITLE') ?? '',
				'entityId' => $roleId,
				'entityType' => 'roles',
			];
		}

		return $result;
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$arErrors,
	): bool
    {
		$arErrors = [];
		$employeeGroupErrors = [];
		$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName(
			$arWorkflowTemplate,
			$activityName,
		);
		$previousEmployeeGroups = self::decodeEmployeeGroups(
			$arCurrentActivity['Properties'][self::PARAM_EMPLOYEE_GROUPS] ?? null,
		);
		if ($previousEmployeeGroups === null)
		{
			$employeeGroupErrors[] = self::getEmployeePreparationError();
		}

		$employeeValue = $arCurrentValues[self::PARAM_EMPLOYEE] ?? '';
		$signersListService = Container::instance()->getSignersListService();
		$availableEmployeeGroupsById = self::loadAvailableEmployeeGroupsById(
			self::extractEmployeeGroupCandidateIds($employeeValue),
			$signersListService,
		);
		$employeeGroupTitlesById = $availableEmployeeGroupsById;
		foreach ($previousEmployeeGroups ?? [] as $previousEmployeeGroup)
		{
			$groupId = $previousEmployeeGroup['id'];
			if (isset($employeeGroupTitlesById[$groupId]))
			{
				$employeeGroupTitlesById[$groupId]['previousTitles'][] = $previousEmployeeGroup['title'];
			}
		}
		[$employeeValue, $employeeGroupIds] = self::splitEmployeeValue(
			$employeeValue,
			$employeeGroupErrors,
			$employeeGroupTitlesById,
		);
		$employeeGroups = self::prepareEmployeeGroups(
			$employeeGroupIds,
			$employeeGroupErrors,
			$signersListService,
			$availableEmployeeGroupsById,
		);

		$arProperties = [
			self::PARAM_RESPONSIBLE => self::getPropertyValue(
				self::PARAM_RESPONSIBLE,
				$arCurrentValues,
				$documentType,
				$arErrors,
			),
			self::PARAM_EMPLOYEE => CBPHelper::usersStringToArray(
				$employeeValue,
				$documentType,
				$arErrors,
			),
			self::PARAM_EMPLOYEE_GROUPS => Main\Web\Json::encode($employeeGroups),
			self::PARAM_REPRESENTATIVE => self::getPropertyValue(
				self::PARAM_REPRESENTATIVE,
				$arCurrentValues,
				$documentType,
				$arErrors,
			),
			self::PARAM_REVIEWER => self::getPropertyValue(
				self::PARAM_REVIEWER,
				$arCurrentValues,
				$documentType,
				$arErrors,
			),
			self::PARAM_EDITOR => self::getPropertyValue(
				self::PARAM_EDITOR,
				$arCurrentValues,
				$documentType,
				$arErrors,
			),
			self::PARAM_TEMPLATE_ID => $arCurrentValues[self::PARAM_TEMPLATE_ID] ?? '',
			self::PARAM_FILE => $arCurrentValues[self::PARAM_FILE]
				?? $arCurrentValues[self::PARAM_FILE . '_text']
				?? '',
			self::PARAM_MESSAGE => self::isDocumentMessageEnabled()
				? (string)($arCurrentValues[self::PARAM_MESSAGE] ?? '')
				: '',
			self::PARAM_IS_SUBSCRIPTION_ENABLED => in_array(
				$arCurrentValues[self::PARAM_IS_SUBSCRIPTION_ENABLED] ?? null,
				['Y', 'N'],
				true
			) ? $arCurrentValues[self::PARAM_IS_SUBSCRIPTION_ENABLED] : '',
		];

		$arErrors = array_merge(
			$arErrors,
			$employeeGroupErrors,
			self::validateProperties(
				$arProperties,
				new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser),
			),
		);

		if ($arErrors)
		{
			return false;
		}

		$arCurrentActivity["Properties"] = $arProperties;

		return true;
	}

	private static function decodeEmployeeGroups(mixed $value): ?array
	{
		if ($value === null || $value === '')
		{
			return [];
		}

		if (!is_string($value))
		{
			return null;
		}

		try
		{
			$decodedGroups = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			return null;
		}

		if (!is_array($decodedGroups))
		{
			return null;
		}

		$employeeGroups = [];
		foreach ($decodedGroups as $group)
		{
			if (
				!is_object($group)
				|| !property_exists($group, 'id')
				|| !property_exists($group, 'title')
				|| !is_int($group->id)
				|| $group->id < 1
				|| !is_string($group->title)
			)
			{
				return null;
			}

			$employeeGroups[] = [
				'id' => $group->id,
				'title' => $group->title,
			];
		}

		return $employeeGroups;
	}

	private function getEmployeeGroups(): ?array
	{
		return self::decodeEmployeeGroups($this->getRawProperty(self::PARAM_EMPLOYEE_GROUPS));
	}

	private static function splitEmployeeValue(
		mixed $value,
		array &$arErrors,
		array $availableEmployeeGroupsById = [],
	): array
	{
		$employeeValue = is_string($value) ? $value : '';
		$serializedEmployeeGroupIds = [];
		$hasInvalidEmployeeGroup = false;
		$serializedGroupLines = self::extractSerializedEmployeeGroupLines($employeeValue);
		foreach ($serializedGroupLines as [$line, $offset])
		{
			if (preg_match('/\[SL([^\[\]\r\n]*)\]\h*$/u', $line, $matches) === 1)
			{
				$groupId = filter_var(
					$matches[1],
					FILTER_VALIDATE_INT,
					['options' => ['min_range' => 1]],
				);
				if ($groupId === false)
				{
					$hasInvalidEmployeeGroup = true;
				}
				else
				{
					$serializedEmployeeGroupIds[] = $groupId;
				}
			}
			elseif (preg_match('/^\x{2063}\h*$/u', $line) !== 1)
			{
				$hasInvalidEmployeeGroup = true;
			}
		}
		$employeeValue = self::removeEmployeeGroupLines($employeeValue, $serializedGroupLines);

		if (CBPActivity::isExpression(trim($employeeValue)))
		{
			if ($hasInvalidEmployeeGroup)
			{
				$arErrors[] = self::getEmployeePreparationError();
			}

			return [$employeeValue, array_values(array_unique($serializedEmployeeGroupIds))];
		}

		$manualEmployeeGroupIds = [];
		preg_match_all(
			'/(?:^|[\r\n])\K[^\r\n]+/u',
			$employeeValue,
			$employeeLines,
			PREG_OFFSET_CAPTURE,
		);
		$manualGroupLines = [];
		foreach ($employeeLines[0] as [$line, $offset])
		{
			$employeePrefix = $line;
			$lineEmployeeGroupIds = [];
			while (preg_match(
				'/\[SL([^\[\]\r\n]*)\]\h*$/u',
				$employeePrefix,
				$matches,
				PREG_OFFSET_CAPTURE,
			) === 1)
			{
				$groupTitle = trim(substr($employeePrefix, 0, $matches[0][1]));
				$groupId = filter_var(
					$matches[1][0],
					FILTER_VALIDATE_INT,
					['options' => ['min_range' => 1]],
				);
				if ($groupId === false)
				{
					$hasInvalidEmployeeGroup = true;

					break;
				}

				$prefixBeforeGroup = self::extractEmployeePrefixBeforeGroupTitle(
					$groupId,
					$groupTitle,
					$availableEmployeeGroupsById,
				);
				if ($prefixBeforeGroup !== null)
				{
					$lineEmployeeGroupIds[] = $groupId;
					$employeePrefix = $prefixBeforeGroup;

					continue;
				}

				$isEmployeeGroupTitle = self::isEmployeeGroupTitle(
					$groupId,
					$groupTitle,
					$availableEmployeeGroupsById,
				);
				if (!$isEmployeeGroupTitle && preg_match('/[,;]\h*$/u', $groupTitle) === 1)
				{
					break;
				}
				if (!$isEmployeeGroupTitle && self::containsEmployeeReference($groupTitle))
				{
					break;
				}
				if (!$isEmployeeGroupTitle && preg_match('/[,;]/u', $groupTitle) === 1)
				{
					$hasInvalidEmployeeGroup = true;

					break;
				}

				$lineEmployeeGroupIds[] = $groupId;
				$employeePrefix = '';

				break;
			}

			if ($lineEmployeeGroupIds === [])
			{
				continue;
			}

			$manualEmployeeGroupIds = array_merge(
				$manualEmployeeGroupIds,
				array_reverse($lineEmployeeGroupIds),
			);
			$manualGroupLines[] = $employeePrefix === ''
				? [$line, $offset]
				: [$line, $offset, $employeePrefix]
			;
		}
		$employeeValue = self::removeEmployeeGroupLines($employeeValue, $manualGroupLines);

		$legacyEmployeeGroupIds = [];
		preg_match_all('/[^,;]+/u', $employeeValue, $tokens, PREG_OFFSET_CAPTURE);

		foreach (array_reverse($tokens[0]) as [$token, $offset])
		{
			if (preg_match('/\[SL([^\[\]]*)\]\s*$/u', $token, $matches, PREG_OFFSET_CAPTURE) === 1)
			{
				$groupTitle = trim(substr($token, 0, $matches[0][1]));
				if (
					self::containsNonSignersListReference($groupTitle)
					&& !self::isEmployeeGroupTitle(
						(int)$matches[1][0],
						$groupTitle,
						$availableEmployeeGroupsById,
					)
				)
				{
					$hasInvalidEmployeeGroup = true;

					continue;
				}

				$groupId = filter_var(
					$matches[1][0],
					FILTER_VALIDATE_INT,
					['options' => ['min_range' => 1]],
				);
				if ($groupId === false)
				{
					$hasInvalidEmployeeGroup = true;
				}
				else
				{
					$legacyEmployeeGroupIds[] = $groupId;
				}
			}
			elseif (preg_match('/\[SL[^\[\]]*$/u', $token) === 1)
			{
				$hasInvalidEmployeeGroup = true;
			}
			else
			{
				continue;
			}

			$start = $offset > 0 ? $offset - 1 : 0;
			$length = strlen($token) + ($offset > 0 ? 1 : 0);
			if ($offset === 0 && isset($employeeValue[$length]))
			{
				$length++;
			}
			$employeeValue = substr_replace($employeeValue, '', $start, $length);

			if (CBPActivity::isExpression(trim($employeeValue)))
			{
				break;
			}
		}

		if ($hasInvalidEmployeeGroup)
		{
			$arErrors[] = self::getEmployeePreparationError();
		}

		$employeeGroupIds = array_merge(
			array_reverse($legacyEmployeeGroupIds),
			$manualEmployeeGroupIds,
			$serializedEmployeeGroupIds,
		);

		return [$employeeValue, array_values(array_unique($employeeGroupIds))];
	}

	private static function extractEmployeeGroupCandidateIds(mixed $value): array
	{
		if (!is_string($value))
		{
			return [];
		}

		$serializedEmployeeGroupIds = [];
		$serializedGroupLines = self::extractSerializedEmployeeGroupLines($value);
		foreach ($serializedGroupLines as [$line])
		{
			if (preg_match('/\[SL(\d+)]\h*$/u', $line, $matches) === 1)
			{
				$serializedEmployeeGroupIds[] = (int)$matches[1];
			}
		}

		$value = self::removeEmployeeGroupLines($value, $serializedGroupLines);
		if (CBPActivity::isExpression(trim($value)))
		{
			return array_values(array_filter(
				array_unique($serializedEmployeeGroupIds),
				static fn(int $groupId): bool => $groupId > 0,
			));
		}

		preg_match_all('/\[SL(\d+)]\h*(?=[,;\r\n]|$)/u', $value, $matches);

		return array_values(array_filter(
			array_unique(array_merge(
				array_map('intval', $matches[1]),
				$serializedEmployeeGroupIds,
			)),
			static fn(int $groupId): bool => $groupId > 0,
		));
	}

	private static function extractSerializedEmployeeGroupLines(string $employeeValue): array
	{
		preg_match_all(
			'/(?:^|[\r\n])\K\x{2063}[^\r\n]*/u',
			$employeeValue,
			$serializedGroupLines,
			PREG_OFFSET_CAPTURE,
		);

		return $serializedGroupLines[0];
	}

	private static function isEmployeeGroupTitle(
		int $groupId,
		string $title,
		array $availableEmployeeGroupsById,
	): bool
	{
		return in_array(
			$title,
			self::getEmployeeGroupTitles($groupId, $availableEmployeeGroupsById),
			true,
		);
	}

	private static function extractEmployeePrefixBeforeGroupTitle(
		int $groupId,
		string $value,
		array $availableEmployeeGroupsById,
	): ?string
	{
		$titles = self::getEmployeeGroupTitles($groupId, $availableEmployeeGroupsById);
		usort($titles, static fn(string $first, string $second): int => strlen($second) <=> strlen($first));
		foreach ($titles as $title)
		{
			if (preg_match(
				'/[,;]\h*' . preg_quote($title, '/') . '$/u',
				$value,
				$matches,
				PREG_OFFSET_CAPTURE,
			) === 1)
			{
				return rtrim(substr($value, 0, $matches[0][1]));
			}
		}

		return null;
	}

	private static function getEmployeeGroupTitles(int $groupId, array $availableEmployeeGroupsById): array
	{
		if (!isset($availableEmployeeGroupsById[$groupId]))
		{
			return [];
		}

		$group = $availableEmployeeGroupsById[$groupId];
		$titles = array_merge([(string)$group['title']], $group['previousTitles'] ?? []);

		return array_values(array_filter(
			array_unique(array_map('trim', $titles)),
			static fn(string $title): bool => $title !== '',
		));
	}

	private static function containsEmployeeReference(string $value): bool
	{
		return self::containsNonSignersListReference($value)
			|| preg_match('/\[SL[^\[\]]*\]/u', $value) === 1
		;
	}

	private static function containsNonSignersListReference(string $value): bool
	{
		return preg_match(
			'/(?:\buser_\d+\b|\{=|\[(?:\d+|G\d+|SG[^\[\]]+|D\d+|DR\d+|HR\d+|HRR\d+|UA)\])/u',
			$value,
		) === 1;
	}

	private static function removeEmployeeGroupLines(string $employeeValue, array $groupLines): string
	{
		foreach (array_reverse($groupLines) as $groupLine)
		{
			[$line, $offset] = $groupLine;
			$replacement = $groupLine[2] ?? null;
			if ($replacement !== null)
			{
				$employeeValue = substr_replace($employeeValue, $replacement, $offset, strlen($line));

				continue;
			}

			$start = $offset;
			$length = strlen($line);
			if ($offset > 1 && substr($employeeValue, $offset - 2, 2) === "\r\n")
			{
				$start -= 2;
				$length += 2;
			}
			elseif ($offset > 0 && in_array($employeeValue[$offset - 1], ["\r", "\n"], true))
			{
				$start--;
				$length++;
			}
			elseif (substr($employeeValue, $length, 2) === "\r\n")
			{
				$length += 2;
			}
			elseif (isset($employeeValue[$length]) && in_array($employeeValue[$length], ["\r", "\n"], true))
			{
				$length++;
			}

			$employeeValue = substr_replace($employeeValue, '', $start, $length);
		}

		return $employeeValue;
	}

	private static function prepareEmployeeGroups(
		array $employeeGroupIds,
		array &$arErrors,
		?Sign\Service\SignersListService $signersListService = null,
		?array $availableGroupsById = null,
	): array
	{
		if (empty($employeeGroupIds))
		{
			return [];
		}

		$signersListService ??= Container::instance()->getSignersListService();
		$availableGroupsById ??= self::loadAvailableEmployeeGroupsById(
			$employeeGroupIds,
			$signersListService,
		);

		$employeeGroups = [];
		$hasUnknownEmployeeGroup = false;
		foreach ($employeeGroupIds as $employeeGroupId)
		{
			if (isset($availableGroupsById[$employeeGroupId]))
			{
				$employeeGroups[] = $availableGroupsById[$employeeGroupId];

				continue;
			}

			$hasUnknownEmployeeGroup = true;
		}

		if ($hasUnknownEmployeeGroup)
		{
			$arErrors[] = self::getEmployeePreparationError();
		}

		return $employeeGroups;
	}

	private static function loadAvailableEmployeeGroupsById(
		array $employeeGroupIds,
		Sign\Service\SignersListService $signersListService,
	): array
	{
		$availableEmployeeGroupIds = array_values(array_filter(
			$employeeGroupIds,
			static fn(int $groupId): bool => !$signersListService->isRejectedList($groupId),
		));
		$availableGroupsById = [];
		foreach (array_chunk($availableEmployeeGroupIds, self::EMPLOYEE_GROUP_QUERY_BATCH_SIZE) as $employeeGroupIdsChunk)
		{
			foreach ($signersListService->listByIds($employeeGroupIdsChunk) as $list)
			{
				$groupId = (int)$list->id;
				if ($groupId < 1)
				{
					continue;
				}

				$availableGroupsById[$groupId] = [
					'id' => $groupId,
					'title' => $list->title,
				];
			}
		}

		return $availableGroupsById;
	}

	private static function resolveSignerUserIds(
		array $directSignerUserIds,
		array $employeeGroups,
		?Sign\Service\SignersListService $signersListService = null,
		?Sign\Service\Sign\MemberService $memberService = null,
	): Main\Result
	{
		$result = new Main\Result();
		if ($employeeGroups === [])
		{
			return $result->setData(['userIds' => $directSignerUserIds]);
		}

		$employeeGroupIds = [];
		foreach ($employeeGroups as $employeeGroup)
		{
			$employeeGroupIds[$employeeGroup['id']] = $employeeGroup['id'];
		}
		$employeeGroupIds = array_values($employeeGroupIds);

		$container = Container::instance();
		$signersListService ??= $container->getSignersListService();
		$memberService ??= $container->getMemberService();

		$existingEmployeeGroupIds = [];
		foreach (array_chunk($employeeGroupIds, self::EMPLOYEE_GROUP_QUERY_BATCH_SIZE) as $employeeGroupIdsChunk)
		{
			foreach ($employeeGroupIdsChunk as $employeeGroupId)
			{
				if ($signersListService->isRejectedList($employeeGroupId))
				{
					return $result->addError(new Main\Error(
						Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_MEMBERS_NOT_SET') ?? '',
					));
				}
			}

			foreach ($signersListService->listByIds($employeeGroupIdsChunk) as $employeeGroup)
			{
				if ($employeeGroup->id !== null)
				{
					$existingEmployeeGroupIds[$employeeGroup->id] = true;
				}
			}
		}

		if (array_diff($employeeGroupIds, array_keys($existingEmployeeGroupIds)) !== [])
		{
			return $result->addError(new Main\Error(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_MEMBERS_NOT_SET') ?? '',
			));
		}

		$directSignerUserIds = array_values(array_filter(
			array_unique(array_map('intval', $directSignerUserIds)),
			static fn(int $userId): bool => $userId > 0,
		));
		$remainingSignerLimit = self::EMPLOYEE_LIMIT - count($directSignerUserIds) + 1;
		$groupSignerUserIds = $remainingSignerLimit > 0
			? $signersListService->listSignerUserIdsByListIds(
				$employeeGroupIds,
				$remainingSignerLimit,
				$directSignerUserIds,
			)
			: []
		;
		$signerUserIds = array_values(array_unique(array_merge(
			$directSignerUserIds,
			$groupSignerUserIds,
		)));
		$entities = new Item\Hr\EntitySelector\EntityCollection();
		foreach ($signerUserIds as $signerUserId)
		{
			$entities->add(new Item\Hr\EntitySelector\Entity(
				$signerUserId,
				Sign\Type\Hr\EntitySelector\EntityType::User,
			));
		}

		return $memberService->resolveUniqueSignerIds($entities, false);
	}

	private static function getEmployeePreparationError(): array
	{
		return [
			'code' => 'InvalidEmployeeGroups',
			'parameter' => self::PARAM_EMPLOYEE,
			'message' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_MEMBERS_NOT_SET'),
		];
	}

	private static function normalizeFileValueToId(mixed $value): int
	{
		if (is_array($value))
		{
			if (\CBPHelper::isAssociativeArray($value))
			{
				$value = array_keys($value);
			}

			$value = reset($value);
		}

		if (!is_numeric($value))
		{
			return 0;
		}

		return (int)$value;
	}

	private static function getPropertyValue(
		string $propertyName,
		array $arCurrentValues,
		array $documentType,
		array &$arErrors
	): array
	{
		$result = [];
		$selectedValues = explode(',', $arCurrentValues[$propertyName] ?? '');
		foreach ($selectedValues as $selectedValue)
		{
			if (self::getStructureNodeService()->getRoleIdByName($selectedValue) !== null)
			{
				$result[] = $selectedValue;

				continue;
			}

			$userArray = CBPHelper::usersStringToArray(
				$selectedValue,
				$documentType,
				$arErrors,
			);
			$userValue = $userArray[0] ?? null;
			if ($userValue === null)
			{
				continue;
			}

			$result[] = $userValue;
		}

		return $result;
	}

	public static function getAjaxResponse(array $params = []): array
	{
		if (!static::isAvailable())
		{
			return [];
		}

		if (($params['request'] ?? null) === 'employeeGroups')
		{
			if (!self::canUseAutomationDocumentSigned($params['automationDocumentSigned'] ?? null))
			{
				return [];
			}

			return self::getEmployeeGroupSelectorItems((string)($params['query'] ?? ''));
		}

		return self::getTemplateSelectOptions();
	}

	private static function getEmployeeGroupSelectorItems(string $query): array
	{
		$signersListService = Container::instance()->getSignersListService();
		$query = trim($query);
		$lists = $query === ''
			? $signersListService->list(self::EMPLOYEE_GROUP_SELECTOR_LIMIT)
			: $signersListService->listWithFilter(
				(new Main\ORM\Query\Filter\ConditionTree())->whereLike('TITLE', "%{$query}%"),
				self::EMPLOYEE_GROUP_SELECTOR_LIMIT,
			)
		;

		$items = [];
		foreach ($lists as $list)
		{
			$items[] = [
				'id' => $list->id,
				'entityId' => 'signers-list',
				'title' => $list->title,
				'searchable' => true,
				'hidden' => false,
				'tabs' => ['signers-list'],
			];
		}

		return $items;
	}

	private static function canUseAutomationDocumentSigned(mixed $documentSigned): bool
	{
		if (!is_string($documentSigned) || $documentSigned === '')
		{
			return false;
		}

		$documentInformation = CBPDocument::unSignParameters($documentSigned);
		if (count($documentInformation) !== 3 || !is_array($documentInformation[0] ?? null))
		{
			return false;
		}

		try
		{
			$documentType = CBPHelper::ParseDocumentId($documentInformation[0]);
		}
		catch (CBPArgumentNullException)
		{
			return false;
		}

		$currentUserId = (int)Main\Engine\CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			return false;
		}

		$templateUser = new CBPWorkflowTemplateUser($currentUserId);
		if ($templateUser->isAdmin())
		{
			return true;
		}

		return CBPDocument::CanUserOperateDocumentType(
			CBPCanUserOperateOperation::CreateAutomation,
			$currentUserId,
			$documentType,
			['DocumentCategoryId' => (int)$documentInformation[1]],
		);
	}

	public function subscribe(IBPActivityExternalEventListener $eventHandler): void
	{
		if ($this->createdDocumentId < 1)
		{
			return;
		}

		$this->isInEventActivityMode = true;
		$schedulerService = $this->workflow->GetService('SchedulerService');
		$eventTypeList = self::getSignB2eEventTypeList();
		foreach ($eventTypeList as $eventType)
		{
			$schedulerService->SubscribeOnEvent(
				$this->workflow->GetInstanceId(),
				$this->name,
				self::EVENT_MODULE_NAME,
				$eventType,
				$this->createdDocumentId,
			);
		}
		$this->workflow->AddEventHandler($this->name, $eventHandler);
		$this->WriteToTrackingService(
			$eventTypeList === []
				? Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_UNABLE_TO_SUBSCRIBE')
				: Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_WAITING_FOR_SIGN'),
		);
	}

	public function unsubscribe(IBPActivityExternalEventListener $eventHandler): void
	{
		if ($this->createdDocumentId < 1)
		{
			return;
		}

		$schedulerService = $this->workflow->GetService("SchedulerService");
		foreach (self::getSignB2eEventTypeList() as $eventType)
		{
			$schedulerService->UnSubscribeOnEvent(
				$this->workflow->GetInstanceId(),
				$this->name,
				self::EVENT_MODULE_NAME,
				$eventType,
				$this->createdDocumentId,
			);
		}
		$this->workflow->removeEventHandler($this->name, $eventHandler);
	}

	public function onExternalEvent($arEventParameters = array()): bool
	{
		if ($this->createdDocumentId < 1)
		{
			return false;
		}

		if ($this->executionStatus === CBPActivityExecutionStatus::Closed)
		{
			return false;
		}

		$eventName = $arEventParameters['eventName'] ?? '';
		if (!in_array($eventName, self::getSignB2eEventTypeList(), true))
		{
			$this->writeToTrackingService(Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_INVALID_EVENT_TYPE'));

			return false;
		}

		$this->{self::RETURN_PARAM_DOCUMENT_ID} = $this->createdDocumentId;
		$this->{self::RETURN_PARAM_DOCUMENT_STATUS} = $eventName === EventData::TYPE_ON_DONE
			? self::RETURN_STATUS_SUCCESS : self::RETURN_STATUS_FAIL;

		$this->unsubscribe($this);
		$this->workflow->closeActivity($this);

		return true;
	}

	/**
	 * Deliberately not a class constant: constants are evaluated on object creation, so unserializing
	 * a workflow stream would resolve sign classes even where the module is not included, e.g. in cron agents.
	 *
	 * @return string[]
	 * @throws Main\LoaderException
	 */
	private static function getSignB2eEventTypeList(): array
	{
		if (!Main\Loader::includeModule(self::EVENT_MODULE_NAME))
		{
			return [];
		}

		return [
			EventData::TYPE_ON_DONE,
			EventData::TYPE_ON_STOPPED,
			EventData::TYPE_ON_CANCELED_BY_RESPONSIBILITY_PERSON,
			EventData::TYPE_ON_CANCELED_BY_REVIEWER,
			EventData::TYPE_ON_CANCELED_BY_EDITOR,
		];
	}

	private function isSubscriptionEnabled(): bool
	{
		return ($this->{self::PARAM_IS_SUBSCRIPTION_ENABLED} ?? 'N') === 'Y';
	}

	private static function getStructureNodeService(): Sign\Service\Integration\HumanResources\StructureNodeService
	{
		return Container::instance()->getHumanResourcesStructureNodeService();
	}

	private function addCrmActivity(Document $document): Main\Result
	{
		$result = new Main\Result();
		if ($this->smartDocumentEntityType === \CCrmOwnerType::Undefined)
		{
			return $result->addError(new Main\Error('Invalid smart document entity type'));
		}

		if ($this->smartDocumentEntityId < 1)
		{
			return $result->addError(new Main\Error('Invalid smart document entity id'));
		}

		$signDocumentEntityId = (int)$document->entityId;

		if ($signDocumentEntityId < 1)
		{
			return $result->addError(new Main\Error('Invalid sign document entity id'));
		}

		$activityId = $this->getCrmActivityId($signDocumentEntityId);

		if ($activityId < 1)
		{
			return $result->addError(new Main\Error('Crm activity not found'));
		}

		$bindings = \CCrmActivity::GetBindings($activityId);
		$bindings[] = [
			'OWNER_ID' => $this->smartDocumentEntityId,
    		'OWNER_TYPE_ID' => $this->smartDocumentEntityType,
		];

		\CCrmActivity::SaveBindings($activityId, $bindings, false, false);

		return $result;
	}

	private function getCrmActivityId(int $entityId): int
	{
		if ($entityId < 1)
		{
			return 0;
		}

		$result = ActivityTable::query()
			->where('BINDINGS.OWNER_ID', $entityId)
			->where('BINDINGS.OWNER_TYPE_ID', \CCrmOwnerType::SmartB2eDocument)
			->where('PROVIDER_ID', \Bitrix\Crm\Activity\Provider\SignB2eDocument::getId())
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;

		return (int)($result['ID'] ?? null);
	}

	private function updateSmartDocumentEntityIdAndType(): Main\Result
	{
		$result = new Main\Result();
		$activityDocument = $this->GetDocumentId();
		$entity = explode('_', ($activityDocument[2] ?? ''));
		$type = sprintf(
			'%s_%s',
			$entity[0] ?? 0,
			$entity[1] ?? 0,
		);
		$this->smartDocumentEntityType = \CCrmOwnerType::ResolveID($type);
		$this->smartDocumentEntityId = (int)($entity[2] ?? 0);

		if ($this->smartDocumentEntityType === \CCrmOwnerType::Undefined)
		{
			return $result->addError(new Main\Error(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_INVALID_SMART_DOCUMENT_ENTITY_TYPE'),
			));
		}

		if ($this->smartDocumentEntityId < 1)
		{
			return $result->addError(new Main\Error(
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_ERROR_INVALID_SMART_DOCUMENT_ENTITY_ID'),
			));
		}

		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($this->smartDocumentEntityType);
		$this->smartDocumentCreatedById = (int)$factory?->getItem($this->smartDocumentEntityId)?->getCreatedBy();

		return $result;
	}
}
