<?php

use Bitrix\Crm\Format;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\TwoPhaseDocumentCreation;
use Bitrix\Crm\Integration\DocumentGenerator\DataProvider;
use Bitrix\Crm\Integration\DocumentGenerator\CreationOperationService;
use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\Service\Container;
use Bitrix\DocumentGenerator\Components\ViewComponent;
use Bitrix\DocumentGenerator\CreationMethod;
use Bitrix\DocumentGenerator\Document;
use Bitrix\DocumentGenerator\Driver;
use Bitrix\DocumentGenerator\Integration\Bitrix24Manager;
use Bitrix\DocumentGenerator\Template;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\Component;
use Bitrix\Main\Error;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorableImplementation;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

if(!Loader::includeModule('documentgenerator'))
{
	die('Module documentgenerator is not installed');
}

Loc::loadMessages(__FILE__);

class CrmDocumentViewComponent extends ViewComponent implements Controllerable, Errorable
{
	use ErrorableImplementation;

	private const TEMPLATE_CODE = 'CRM_DOCUMENT_SHARING';
	private const TEMPLATE_PLACEHOLDER = 'DOCUMENT_URL';
	private const READY_DOCUMENT_KEYS = [
		'id', 'title', 'number', 'downloadUrl', 'publicUrl', 'publicUrlView', 'pdfUrl', 'printUrl',
		'imageUrl', 'pullTag', 'emailDiskFile', 'isTransformationError', 'transformationErrorMessage',
		'transformationErrorCode', 'transformationCancelReason', 'stampsEnabled', 'createTime', 'createdBy',
		'updateTime', 'updatedBy', 'templateId', 'provider', 'value', 'values', 'changeStampsEnabled',
		'changeStampsDisabledReason', 'changeQrCodeEnabled', 'qrCodeEnabled', 'changeQrCodeDisabledReason',
		'myCompanyEditUrl', 'myCompanyRequisites', 'clientRequisites', 'isSigningEnabled', 'editTemplateUrl',
		'PROVIDER', 'TEMPLATE_NAME', 'TEMPLATE_CODE', 'documentUrl', 'editDocumentUrl',
		'channelSelectorParameters',
	];

	private ?Error $creationValidationError = null;
	private array $operationBindingStates = [];

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->errorCollection = new ErrorCollection();
	}

	public function configureActions(): array
	{
		return [
			'createDocument' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
		];
	}

	protected function listKeysSignedParameters(): array
	{
		return ['TEMPLATE_ID', 'PROVIDER', 'VALUE', 'VALUES', 'NUMBER', 'MODE', 'DOCUMENT_ID'];
	}

	public function executeComponent()
	{
		if (!$this->includeModules())
		{
			ShowError(Loc::getMessage('CRM_DOCUMENT_VIEW_COMPONENT_MODULE_ERROR'));
			return;
		}
		if ($this->arParams['MODE'] === 'change' && !$this->isSessionValid())
		{
			ShowError(Loc::getMessage('CRM_DOCUMENT_VIEW_COMPONENT_CSRF_ERROR'));
			return;
		}
		$isTwoPhaseFlow = $this->shouldRenderTwoPhaseShell();
		if ($isTwoPhaseFlow && $this->isLimitPageRequested() && $this->isDocumentCreationLimitReached())
		{
			$this->renderLimitTemplate();

			return;
		}
		if ($isTwoPhaseFlow)
		{
			$this->prepareInitialShell();
			$this->includeComponentTemplate();

			return;
		}
		$result = $this->initDocument();
		if (!$result->isSuccess())
		{
			$this->arResult['ERRORS'] = $result->getErrorMessages();
			$this->includeComponentTemplate();
			return;
		}
		Container::getInstance()->getLocalization()->loadMessages();
		if ($this->document->ID > 0 && $this->arParams['MODE'] === 'edit')
		{
			if (!Driver::getInstance()->getUserPermissions()->canModifyDocument($this->document))
			{
				$this->arResult['ERRORS'] = [Loc::getMessage('CRM_DOCUMENT_VIEW_DOCUMENT_ACCESS_ERROR')];
				$this->includeComponentTemplate();
				return;
			}
			$this->arResult['FIELDS'] = $this->document->getFields([], true, true);
			$this->includeComponentTemplate('edit');
			return;
		}
		if (!$this->document->ID && Bitrix24Manager::isEnabled() && Bitrix24Manager::isDocumentsLimitReached())
		{
			$this->includeComponentTemplate('limit');
			return;
		}
		$isNewDocument = true;
		if ($this->document->ID > 0)
		{
			$isNewDocument = false;
		}

		if (
			$this->arParams['MODE'] === 'change'
			&& $this->document->ID > 0
			&& Driver::getInstance()->getUserPermissions()->canModifyDocument($this->document)
		)
		{
			$result = $this->document->update($this->arParams['VALUES']);
		}
		else
		{
			if ($isNewDocument)
			{
				CreationMethod::markDocumentAsCreatedByPublic($this->document);
			}

			$isSendToTransformation = !$this->document->PDF_ID;
			$result = $this->document->getFile($isSendToTransformation);
		}

		$this->arResult = $result->getData();

		if (
			$isSendToTransformation
			&& !empty($this->arResult['transformationCancelReason'])
			&& $this->arResult['transformationCancelReason'] instanceof \Bitrix\Main\Error
			&& $this->arResult['transformationCancelReason']->getCode() === 'TRANSFORM_FORMATS_PROCESSED'
		)
		{
			$this->arResult['isTransformationError'] = true;
			$this->arResult['transformationErrorMessage'] = Loc::getMessage('CRM_DOCUMENT_VIEW_COMPONENT_PROCESSED_NO_PDF_ERROR_MSGVER_1');
		}
		if (!$result->isSuccess())
		{
			if (($this->arResult['isTransformationError'] ?? false) !== true)
			{
				$this->arResult['ERRORS'] = $result->getErrorMessages();
			}
		}
		$this->arResult['isDisplayTransformationErrors'] =
			\Bitrix\Main\Config\Option::get('crm', 'display_transformation_errors_in_document_slider', 'Y') === 'Y'
		;
		if ($isNewDocument)
		{
			$this->arResult['documentUrl'] = $this->getDocumentUrl();
		}
		$this->arResult['values'] = $this->arParams['VALUES'];
		$this->arResult['editTemplateUrl'] = $this->getEditTemplateUrl();
		if ($this->arResult['editTemplateUrl'])
		{
			$this->arResult['editDocumentUrl'] = $this->getEditDocumentUrl();
		}
		$this->arResult['myCompanyRequisites'] = $this->createEmptyRequisiteData();
		$this->arResult['clientRequisites'] = $this->createEmptyRequisiteData();
		$provider = $this->document->getProvider();
		if ($provider instanceof DataProvider\CrmEntityDataProvider)
		{
			$this->arResult['PROVIDER'] = get_class($provider);
			$this->fillRequisiteData($this->arResult, $provider);
			$this->arResult['isSigningEnabled'] =
				($provider instanceof \Bitrix\Crm\Integration\DocumentGenerator\DataProvider\Deal)
				&& \Bitrix\Crm\Service\Container::getInstance()
					->getUserPermissions()
					->entityType()
					->canAddItems(\CCrmOwnerType::SmartDocument)
				&& \Bitrix\Crm\Settings\Crm::isDocumentSigningEnabled()
				&& \Bitrix\Sign\Config\Storage::instance()->isAvailable()
			;
		}
		else
		{
			$this->arResult['PROVIDER'] = '';
			$this->arResult['editMyCompanyRequisitesUrl'] = '';
		}
		$this->arResult['TEMPLATE_NAME'] = '';
		$this->arResult['TEMPLATE_CODE'] = '';
		if ($this->template)
		{
			$this->arResult['TEMPLATE_NAME'] = $this->template->NAME;
			$this->arResult['TEMPLATE_CODE'] = $this->template->CODE;
		}

		if ($this->getValue() > 0 && $this->document->FILE_ID > 0)
		{
			$link = $this->document->getPublicUrl();
			$this->arResult['channelSelectorParameters'] = [
				'id' => 'document-channel-selector',
				'entityTypeId' => (int)$this->getCrmOwnerType(),
				'entityId' => (int)$this->getValue(),
				'documentTitle' => $this->document->getTitle(),
				'body' => Loc::getMessage('CRM_DOCUMENT_VIEW_COMPONENT_BODY_MSGVER_1'),
				'fullBody' => Loc::getMessage('CRM_DOCUMENT_VIEW_COMPONENT_FULL_BODY_MSGVER_1'),
				'configureContext' => 'crm.document.view',
				'link' => $link,
				'isLinkObtainable' => true,
				'isCombineMessageWithLink' => false,
				'isInsertLinkInMessage' => true,
				'isConfigurable' => true,
				'signedTemplate' => $this->getSignedTemplate(),
				'needCommonPhoneChannel' => true,
				'messageSenderSceneId' => 'crm.document.view',
				'analytics' => [
					'c_section' => \Bitrix\Crm\Integration\Analytics\Dictionary::SECTION_DOCUMENT,
				]
			];
			if (Driver::getInstance()->getDefaultStorage() instanceof \Bitrix\DocumentGenerator\Storage\Disk)
			{
				$this->arResult['channelSelectorParameters']['storageTypeId'] = \Bitrix\Crm\Integration\StorageType::Disk;
				$this->arResult['channelSelectorParameters']['files'] = [$this->getEmailDiskFile(true)];
			}
		}
		$signIntegration = ServiceLocator::getInstance()->get('crm.integration.sign');

		$this->arResult['isSigningEnabledInCurrentTariff'] = $signIntegration->isEnabledInCurrentTariff();
		$this->arResult['signingInfoHelperSliderCode'] = 'limit_crm_sign_integration';

		$this->arResult['baas']['fastTransform'] = [
			'isAvailable' => DocumentGeneratorManager::getInstance()->isBaasFastTransformFeatureAvailable(),
			'isActive' => DocumentGeneratorManager::getInstance()->isBaasFastTransformFeatureActive(),
		];

		$this->includeComponentTemplate();
	}

	public function createDocumentAction(string $operationId): ?Component
	{
		$this->errorCollection = new ErrorCollection();
		if (!$this->includeModules())
		{
			$this->addCreationValidationError($operationId);

			return null;
		}

		$this->loadCrmLocalizationMessages();
		if (!$this->hasAuthoritativeCreationContext())
		{
			$this->addCreationValidationError($operationId);

			return null;
		}

		$result = $this->runCreationOperation($operationId);
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$customData = is_array($error->getCustomData()) ? $error->getCustomData() : [];
				$this->addStructuredError(
					$error,
					$this->getFailureStage($error),
					$customData['operation'] ?? $this->createOperationData($operationId),
				);
			}

			return null;
		}

		$resultData = $result->getData();
		$document = $resultData['document'] ?? null;
		if (!$document instanceof Document)
		{
			$this->addStructuredError(
				new Error(
					(string)Loc::getMessage('CRM_DOCUMENT_VIEW_DOCUMENT_CREATION_FAILED'),
					CreationOperationService::ERROR_FAILED,
				),
				'docxAssembly',
				$resultData['operation'] ?? $this->createOperationData($operationId),
			);

			return null;
		}

		$this->document = $document;
		$this->template = $document->getTemplate();
		$this->value = $this->arParams['VALUE'];
		$documentData = $this->prepareReadyDocumentData((array)($resultData['documentData'] ?? []));
		$selectorParameters = (array)($documentData['channelSelectorParameters'] ?? []);

		return new Component(
			'bitrix:crm.channel.selector',
			'',
			$selectorParameters,
			[
				'operation' => $resultData['operation'],
				'document' => $documentData,
			],
		);
	}

	protected function runCreationOperation(string $operationId): Result
	{
		$templateId = (int)$this->arParams['TEMPLATE_ID'];
		if (!$this->canCreateDocumentOnTemplate($templateId))
		{
			return $this->createValidationResult(
				$operationId,
				'DOCUMENT_TEMPLATE_ACCESS_DENIED',
				(string)Loc::getMessage('CRM_DOCUMENT_VIEW_DOCUMENT_MODIFY_PERMISSION_ERROR'),
			);
		}

		$template = $this->loadCreationTemplate($templateId);
		if (!$template || $template->isDeleted())
		{
			return $this->createValidationResult(
				$operationId,
				'DOCUMENT_TEMPLATE_NOT_FOUND',
				(string)Loc::getMessage('CRM_DOCUMENT_VIEW_TEMPLATE_NOT_FOUND_ERROR'),
			);
		}
		if ($template->MODULE_ID !== $this->getModule())
		{
			return $this->createValidationResult(
				$operationId,
				'DOCUMENT_TEMPLATE_ACCESS_DENIED',
				(string)Loc::getMessage('CRM_DOCUMENT_VIEW_DOCUMENT_ACCESS_ERROR'),
			);
		}

		$template->setSourceType((string)$this->arParams['PROVIDER']);
		$this->creationValidationError = null;
		$service = $this->getCreationOperationService();
		$result = $service->run(
			$operationId,
				(int)CurrentUser::get()->getId(),
				$this->getSignedContextHash(),
				function(array $data) use ($template): ?Document {
					$signedNumber = (string)($this->arParams['NUMBER'] ?? '');
					if (!array_key_exists('NUMBER', $data) && $signedNumber !== '')
					{
						$data['NUMBER'] = $signedNumber;
					}

					$document = $this->createDocumentByTemplate($template, $this->arParams['VALUE'], $data);
					if (!$document instanceof Document)
					{
						return null;
					}
					if (!$document->hasAccess())
					{
						$this->creationValidationError = new Error(
							(string)Loc::getMessage('CRM_DOCUMENT_VIEW_DOCUMENT_ACCESS_ERROR'),
							'DOCUMENT_ENTITY_ACCESS_DENIED',
						);

						return null;
					}
					if ($this->isDocumentCreationLimitReached())
					{
						$this->creationValidationError = new Error(
							(string)Loc::getMessage('CRM_DOCUMENT_VIEW_DOCUMENTS_LIMIT_ERROR'),
							Bitrix24Manager::LIMIT_ERROR_CODE,
						);

						return null;
					}

					$document->setValues((array)$this->arParams['VALUES']);

					return $document;
				},
				function(int $documentId): ?Document {
					$document = $this->loadCreatedDocument($documentId);
					if (!$document instanceof Document || !$document->hasAccess())
					{
						return null;
					}
					$template = $document->getTemplate();
					if (!$template || $template->MODULE_ID !== $this->getModule())
					{
						return null;
					}

					return $document;
				},
		);

		if (!$result->isSuccess() && $this->creationValidationError !== null)
		{
			return (new Result())->addError(new Error(
				$this->creationValidationError->getMessage(),
				$this->creationValidationError->getCode(),
				['operation' => $this->createOperationData($operationId)],
			));
		}

		return $result;
	}

	protected function normalizeReadyDocumentData(array $data): array
	{
		$document = array_fill_keys(self::READY_DOCUMENT_KEYS, null);

		return array_replace($document, array_intersect_key($data, $document));
	}

	protected function getCreationOperationService(): CreationOperationService
	{
		return new CreationOperationService();
	}

	protected function loadCrmLocalizationMessages(): void
	{
		Container::getInstance()->getLocalization()->loadMessages();
	}

	protected function addCreationValidationError(string $operationId): void
	{
		$this->addStructuredError(
			new Error(
				(string)Loc::getMessage('CRM_DOCUMENT_VIEW_UNKNOWN_ERROR'),
				'DOCUMENT_CREATION_FAILED',
			),
			'validation',
			$this->createOperationData($operationId),
		);
	}

	protected function createDocumentByTemplate(Template $template, mixed $value, array $data): ?Document
	{
		return Document::createByTemplate($template, $value, $data);
	}

	protected function loadCreatedDocument(int $documentId): ?Document
	{
		return Document::loadById($documentId);
	}

	protected function canCreateDocumentOnTemplate(int $templateId): bool
	{
		return Driver::getInstance()->getUserPermissions()->canCreateDocumentOnTemplate($templateId);
	}

	protected function loadCreationTemplate(int $templateId): ?Template
	{
		return Template::loadById($templateId);
	}

	protected function isDocumentCreationLimitReached(): bool
	{
		return Bitrix24Manager::isEnabled() && Bitrix24Manager::isDocumentsLimitReached();
	}

	protected function isLimitPageRequested(): bool
	{
		return $this->request->getQuery('showLimit') === 'Y';
	}

	protected function renderLimitTemplate(): void
	{
		$this->includeComponentTemplate('limit');
	}

	protected function isTwoPhaseDocumentCreationEnabled(): bool
	{
		return Feature::enabled(TwoPhaseDocumentCreation::class);
	}

	protected function isSessionValid(): bool
	{
		return check_bitrix_sessid();
	}

	protected function resolveOperationBindingForCurrentContext(string $operationId): string
	{
		return $this->getCreationOperationService()->resolveOperationBinding(
			$operationId,
			(int)CurrentUser::get()->getId(),
			$this->getSignedContextHash(),
		);
	}

	protected function getRequestedOperationId(): ?string
	{
		$operationId = $this->request->getQuery('operationId');

		return is_string($operationId) && $operationId !== '' ? $operationId : null;
	}

	protected function generateDocumentCreationOperationId(): string
	{
		return $this->getCreationOperationService()->generateOperationId();
	}

	protected function shouldRenderTwoPhaseShell(): bool
	{
		if ((int)($this->arParams['DOCUMENT_ID'] ?? 0) > 0 || ($this->arParams['MODE'] ?? '') === 'edit')
		{
			return false;
		}

		if ($this->isTwoPhaseDocumentCreationEnabled())
		{
			return true;
		}

		$requestedOperationId = $this->getRequestedOperationId();
		if ($requestedOperationId === null)
		{
			return false;
		}

		return $this->shouldKeepRequestedOperationId($requestedOperationId);
	}

	private function prepareInitialShell(): void
	{
		Container::getInstance()->getLocalization()->loadMessages();
		$requestedOperationId = $this->getRequestedOperationId();
		$operationId = null;
		if (
			$requestedOperationId !== null
			&& $this->shouldKeepRequestedOperationId($requestedOperationId)
		)
		{
			$operationId = $requestedOperationId;
		}
		$operationId ??= $this->generateDocumentCreationOperationId();
		$signIntegration = ServiceLocator::getInstance()->get('crm.integration.sign');

		$this->arResult = [
			'ERRORS' => [],
			'isDisplayTransformationErrors' =>
				\Bitrix\Main\Config\Option::get(
					'crm',
					'display_transformation_errors_in_document_slider',
					'Y',
				) === 'Y',
			'isSigningEnabledInCurrentTariff' => $signIntegration->isEnabledInCurrentTariff(),
			'signingInfoHelperSliderCode' => 'limit_crm_sign_integration',
			'baas' => [
				'fastTransform' => [
					'isAvailable' => DocumentGeneratorManager::getInstance()->isBaasFastTransformFeatureAvailable(),
					'isActive' => DocumentGeneratorManager::getInstance()->isBaasFastTransformFeatureActive(),
				],
			],
			'initialTitle' => (string)Loc::getMessage('CRM_COMMON_DOCUMENT'),
			'operation' => [
				'id' => $operationId,
				'state' => 'new',
				'documentId' => null,
				'retryAllowed' => true,
				'retryAfter' => null,
				'docxAvailable' => false,
			],
			'signedParameters' => (string)$this->getSignedParameters(),
		];
	}

	private function shouldKeepRequestedOperationId(string $operationId): bool
	{
		return in_array(
			$this->getOperationBindingForCurrentContext($operationId),
			[
				CreationOperationService::BINDING_BOUND,
				CreationOperationService::BINDING_UNAVAILABLE,
			],
			true,
		);
	}

	private function getOperationBindingForCurrentContext(string $operationId): string
	{
		$cacheKey = implode(':', [
			$operationId,
			(int)CurrentUser::get()->getId(),
			$this->getSignedContextHash(),
		]);

		if (!array_key_exists($cacheKey, $this->operationBindingStates))
		{
			$this->operationBindingStates[$cacheKey] = $this->resolveOperationBindingForCurrentContext($operationId);
		}

		return $this->operationBindingStates[$cacheKey];
	}

	private function hasAuthoritativeCreationContext(): bool
	{
		return (int)($this->arParams['TEMPLATE_ID'] ?? 0) > 0
			&& is_string($this->arParams['PROVIDER'] ?? null)
			&& $this->arParams['PROVIDER'] !== ''
			&& isset($this->arParams['VALUE'])
		;
	}

	private function getSignedContextHash(): string
	{
		$context = [];
		foreach ($this->listKeysSignedParameters() as $key)
		{
			$context[$key] = $this->arParams[$key] ?? null;
		}

		return hash('sha256', serialize($context));
	}

	private function createValidationResult(string $operationId, string $code, string $message): Result
	{
		return (new Result())->addError(new Error(
			$message,
			$code,
			['operation' => $this->createOperationData($operationId)],
		));
	}

	private function createOperationData(string $operationId): array
	{
		return [
			'id' => $operationId,
			'state' => 'failed',
			'documentId' => null,
			'retryAllowed' => false,
			'retryAfter' => null,
			'docxAvailable' => false,
		];
	}

	private function addStructuredError(Error $error, string $failureStage, array $operation): void
	{
		$this->errorCollection->setError(new Error(
			$error->getMessage(),
			$error->getCode(),
			[
				'operation' => $operation,
				'failureStage' => $failureStage,
			],
		));
	}

	private function getFailureStage(Error $error): string
	{
		$errorCode = $error->getCode();
		if ($errorCode === CreationOperationService::ERROR_FAILED)
		{
			$customData = is_array($error->getCustomData()) ? $error->getCustomData() : [];
			$operation = is_array($customData['operation'] ?? null) ? $customData['operation'] : [];
			if (
				(int)($operation['documentId'] ?? 0) <= 0
				&& ($operation['retryAllowed'] ?? null) === false
				&& ($operation['retryAfter'] ?? null) === null
			)
			{
				return 'validation';
			}
		}

		return match ($errorCode) {
			CreationOperationService::ERROR_BUSY,
			CreationOperationService::ERROR_FAILED,
			CreationOperationService::ERROR_RECONCILIATION_REQUIRED => 'docxAssembly',
			default => 'validation',
		};
	}

	protected function prepareReadyDocumentData(array $documentData): array
	{
		$documentData['values'] = $this->resolveReadyDocumentValues($documentData);
		$documentData['editTemplateUrl'] = $this->getEditTemplateUrl();
		$documentData['documentUrl'] = $this->getDocumentUrl(true);
		$documentData['editDocumentUrl'] = $documentData['editTemplateUrl']
			? $this->getEditDocumentUrl(true)
			: null
		;
		$documentData['myCompanyRequisites'] = $this->createEmptyRequisiteData();
		$documentData['clientRequisites'] = $this->createEmptyRequisiteData();
		$documentData['isSigningEnabled'] = false;
		$documentData['PROVIDER'] = '';

		$provider = $this->document->getProvider();
		if ($provider instanceof DataProvider\CrmEntityDataProvider)
		{
			$documentData['PROVIDER'] = get_class($provider);
			$this->fillRequisiteData($documentData, $provider);
			$documentData['isSigningEnabled'] =
				($provider instanceof DataProvider\Deal)
				&& Container::getInstance()->getUserPermissions()->entityType()->canAddItems(
					\CCrmOwnerType::SmartDocument,
				)
				&& \Bitrix\Crm\Settings\Crm::isDocumentSigningEnabled()
				&& \Bitrix\Sign\Config\Storage::instance()->isAvailable()
			;
		}

		$documentData['TEMPLATE_NAME'] = $this->template?->NAME ?? '';
		$documentData['TEMPLATE_CODE'] = $this->template?->CODE ?? '';
		$documentData['channelSelectorParameters'] = $this->getChannelSelectorParameters();

		return $this->normalizeReadyDocumentData($documentData);
	}

	private function resolveReadyDocumentValues(array $documentData): array
	{
		// Document values are authoritative: the signed context is a snapshot taken when the slider
		// was opened, so merging its keys would resurrect overrides the user has already dropped.
		return isset($documentData['values'])
			? (array)$documentData['values']
			: (array)$this->arParams['VALUES']
		;
	}

	private function fillRequisiteData(array &$documentData, DataProvider\CrmEntityDataProvider $provider): void
	{
		$myCompanyProvider = $provider->getMyCompanyProvider();
		if ($myCompanyProvider)
		{
			[$requisiteData, ] = $myCompanyProvider->getClientRequisitesAndBankDetail();
			$myCompanyId = $myCompanyProvider->getSource();
			$link = is_numeric($myCompanyId) && (int)$myCompanyId > 0
				? Container::getInstance()->getRouter()->getItemDetailUrl(
					\CCrmOwnerType::Company,
					(int)$myCompanyId,
				)
				: null
			;
			$documentData['myCompanyRequisites'] = [
				'title' =>
					Format\Requisite::formatOrganizationName($requisiteData)
					?? $this->toRequisiteText($myCompanyProvider->getValue('TITLE'))
					?: $documentData['myCompanyRequisites']['title'],
				'link' => $this->toRequisiteText($link) ?? $documentData['myCompanyRequisites']['link'],
				'subTitle' =>
					Format\Requisite::formatShortRequisiteString($requisiteData)
					?? $documentData['myCompanyRequisites']['subTitle'],
			];
		}

		[$clientRequisiteData, ] = $provider->getClientRequisitesAndBankDetail();
		$companyProvider = $provider->getValue('COMPANY');
		if ($companyProvider instanceof DataProvider\Company)
		{
			$documentData['clientRequisites'] = [
				'title' =>
					Format\Requisite::formatOrganizationName($clientRequisiteData)
					?? $this->toRequisiteText($companyProvider->getValue('TITLE'))
					?: $documentData['clientRequisites']['title'],
				'link' => $this->toRequisiteText(Container::getInstance()->getRouter()->getItemDetailUrl(
					\CCrmOwnerType::Company,
					(int)$companyProvider->getSource(),
				)) ?? $documentData['clientRequisites']['link'],
				'subTitle' =>
					Format\Requisite::formatShortRequisiteString($clientRequisiteData)
					?? $documentData['clientRequisites']['subTitle'],
			];

			return;
		}

		$contactProvider = $provider->getValue('CONTACT');
		if ($contactProvider instanceof DataProvider\Contact)
		{
			$documentData['clientRequisites'] = [
				'title' =>
					Format\Requisite::formatOrganizationName($clientRequisiteData)
					?? $this->toRequisiteText($contactProvider->getValue('FORMATTED_NAME'))
					?: $documentData['clientRequisites']['title'],
				'link' => $this->toRequisiteText(Container::getInstance()->getRouter()->getItemDetailUrl(
					\CCrmOwnerType::Contact,
					(int)$contactProvider->getSource(),
				)) ?? $documentData['clientRequisites']['link'],
				'subTitle' =>
					Format\Requisite::formatShortRequisiteString($clientRequisiteData)
					?? $documentData['clientRequisites']['subTitle'],
			];
		}
	}

	private function createEmptyRequisiteData(): array
	{
		return [
			'title' => Loc::getMessage('CRM_COMMON_EMPTY_VALUE'),
			'link' => null,
			'subTitle' => '',
		];
	}

	/**
	 * Provider values and routing urls are objects rendering themselves as text,
	 * but json keeps them as objects, so the sidebar contract is a plain string.
	 */
	private function toRequisiteText(mixed $value): ?string
	{
		if (is_string($value))
		{
			return $value;
		}

		return is_numeric($value) || $value instanceof \Stringable ? (string)$value : null;
	}

	private function getChannelSelectorParameters(): ?array
	{
		if ($this->getValue() <= 0 || $this->document->FILE_ID <= 0)
		{
			return null;
		}

		$parameters = [
			'id' => 'document-channel-selector',
			'entityTypeId' => (int)$this->getCrmOwnerType(),
			'entityId' => (int)$this->getValue(),
			'documentTitle' => $this->document->getTitle(),
			'body' => Loc::getMessage('CRM_DOCUMENT_VIEW_COMPONENT_BODY_MSGVER_1'),
			'fullBody' => Loc::getMessage('CRM_DOCUMENT_VIEW_COMPONENT_FULL_BODY_MSGVER_1'),
			'configureContext' => 'crm.document.view',
			'link' => $this->document->getPublicUrl(),
			'isLinkObtainable' => true,
			'isCombineMessageWithLink' => false,
			'isInsertLinkInMessage' => true,
			'isConfigurable' => true,
			'signedTemplate' => $this->getSignedTemplate(),
			'needCommonPhoneChannel' => true,
			'messageSenderSceneId' => 'crm.document.view',
			'analytics' => [
				'c_section' => \Bitrix\Crm\Integration\Analytics\Dictionary::SECTION_DOCUMENT,
			],
		];
		if (Driver::getInstance()->getDefaultStorage() instanceof \Bitrix\DocumentGenerator\Storage\Disk)
		{
			$parameters['storageTypeId'] = \Bitrix\Crm\Integration\StorageType::Disk;
			$parameters['files'] = [$this->getEmailDiskFile(true)];
		}

		return $parameters;
	}

	/**
	 * @return string
	 */
	protected function getModule()
	{
		return 'crm';
	}

	/**
	 * @return string|false
	 */
	protected function getEditDocumentUrl(bool $usePublicPath = false)
	{
		if (Driver::getInstance()->getUserPermissions()->canModifyDocument($this->document))
		{
			$uri = $this->getDocumentBaseUri($usePublicPath);

			return $uri->deleteParams(['mode', 'templateId', 'values', 'value', 'provider'])
				->addParams(['documentId' => $this->document->ID, 'mode' => 'edit'])
				->getLocator()
			;
		}

		return false;
	}

	/**
	 * @return CrmEntityDataProvider|false
	 */
	protected function getCrmDataProvider()
	{
		if ($this->document)
		{
			$provider = $this->document->getProvider();
			if ($provider instanceof DataProvider\CrmEntityDataProvider)
			{
				return $provider;
			}
		}

		return false;
	}

	/**
	 * @return int
	 */
	public function getCrmOwnerType(): int
	{
		$result = 0;

		$provider = $this->getCrmDataProvider();
		if ($provider)
		{
			$result = $provider->getCrmOwnerType();
		}

		return $result;
	}

	/**
	 * @param bool $docx
	 * @return int
	 */
	protected function getEmailDiskFile($docx = false): int
	{
		$result = 0;

		if ($this->document)
		{
			$result = $this->document->getEmailDiskFile($docx);
		}

		return $result;
	}

	/**
	 * @return string
	 */
	protected function getDocumentUrl(bool $usePublicPath = false): string
	{
		$uri = $this->getDocumentBaseUri($usePublicPath);

		return $uri->deleteParams(['mode', 'templateId', 'values', 'value', 'provider'])
			->addParams(['documentId' => $this->document->ID])
			->getLocator()
		;
	}

	private function getDocumentBaseUri(bool $usePublicPath): Uri
	{
		if (!$usePublicPath)
		{
			return new Uri(Application::getInstance()->getContext()->getRequest()->getRequestUri());
		}

		$componentPath = \CComponentEngine::makeComponentPath('bitrix:crm.document.view');
		$uri = new Uri(getLocalPath('components' . $componentPath . '/slider.php'));
		$siteId = $this->getSiteId() ?: (defined('SITE_ID') ? SITE_ID : '');
		if ($siteId !== '')
		{
			$uri->addParams(['site_id' => $siteId]);
		}

		return $uri;
	}

	/**
	 * @return bool|string
	 */
	protected function getEditTemplateUrl()
	{
		if ($this->template && !$this->template->isDeleted())
		{
			if (!Driver::getInstance()->getUserPermissions()->canModifyTemplate($this->template->ID))
			{
				return true;
			}
			$addUrl = DocumentGeneratorManager::getInstance()->getAddTemplateUrl();
			if ($addUrl)
			{
				$editUrl = new Uri($addUrl);
				$editUrl->addParams([
					'ID' => $this->template->ID,
					'UPLOAD' => 'Y',
				]);

				return $editUrl->getLocator();
			}
		}

		return false;
	}

	private function getSignedTemplate(): ?string
	{
		$documentUrl = $this->document->getPublicUrl();

		if (!$documentUrl)
		{
			return null;
		}

		return NotificationsManager::signTemplate(
			self::TEMPLATE_CODE,
			[
				[
					'name' => self::TEMPLATE_PLACEHOLDER,
					'value' => (string)$documentUrl,
				],
			],
		);
	}
}
