<?php

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SetConstantsRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateService\SetConstantsResponse;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\FileUploader\SetupTemplateUploaderController;
use Bitrix\Bizproc\Integration\Push\PushWorker;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Block;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\BlockCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Constant;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Delimiter;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\DelimiterType;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Description;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Item;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ItemCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ItemType;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Title;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\TitleWithIcon;
use Bitrix\Bizproc\Internal\Event\SetupTemplateCurrentDataEvent;
use Bitrix\Bizproc\Internal\Event\SetupTemplateUserInputEvent;
use Bitrix\Bizproc\Internal\Event\SetupTemplateValidationEvent;
use Bitrix\Bizproc\Internal\Integration\Rag\DocumentFieldTypes\RagKnowledgeBaseType;
use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectType;
use Bitrix\Bizproc\Internal\Integration\UI\UploaderHelper;
use Bitrix\Bizproc\Internal\Service\DocumentField\AccessValidationService;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate;
use Bitrix\Bizproc\WorkflowTemplateTable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use Bitrix\UI\FileUploader\Uploader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPSetupTemplateActivity extends CBPActivity implements IBPEventActivity, IBPActivityExternalEventListener
{
	private const PARAM_BLOCKS = 'blocks';
	private const PARAM_BLOCK_ITEMS = 'items';
	private const PARAM_BLOCK_ITEMS_ITEM_TYPE = 'itemType';
	private const PUSH_COMMAND = 'setupTemplateActivityBlocks';
	private const ERROR_MESSAGE = 'message';
	private const ERROR_CODE = 'code';
	private const ERROR_CONSTANT = 'constant';
	private const ERROR_CODE_NOT_EXIST = 'NotExist';
	private const ERROR_CODE_INVALID_STRUCTURE = 'InvalidStructJson';
	private const ERROR_CODE_UNKNOWN_ITEM_TYPE = 'UnknownItemType';
	private const ERROR_CODE_UNKNOWN_FIELD_TYPE = 'UnknownFieldType';
	private const EXPIRES_IN = 24 * 60 * 60;
	private int $subscriptionId = 0;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_BLOCKS => null,
		];
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$arErrors = [];
		if (empty($arTestProperties[self::PARAM_BLOCKS]))
		{
			$arErrors[] = self::makeValidationError(
				Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCKS_EMPTY')
			);
		}
		else
		{
			self::validateAndParseBlocks($arTestProperties[self::PARAM_BLOCKS], $arErrors);
		}

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = '',
	): PropertiesDialog
	{
		$dialog = new PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);

		$dialog->setMap(static::getPropertiesMap($documentType));
		$dialog->setRuntimeData([
			'typeNames' => self::getAllowedConstantTypes($documentType),
		]);

		return $dialog;
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$errors,
	): bool
	{
		$errors = [];
		$properties = [];

		$documentService = CBPRuntime::getRuntime()->getDocumentService();
		foreach (static::getPropertiesMap($documentType) as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$arCurrentValues,
				$errors
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$errors = self::validateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser)
		);

		if (!empty($errors))
		{
			return false;
		}

		$collection = self::validateAndParseBlocks($properties[self::PARAM_BLOCKS], $errors);

		if (!empty($errors))
		{
			return false;
		}

		$properties[self::PARAM_BLOCKS] = $collection->toArray();

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_BLOCKS => [
				'Name' => Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCKS'),
				'FieldName' => self::PARAM_BLOCKS,
				'Type' => FieldType::CUSTOM,
				'Getter' => static function($dialog, $property, $currentActivity)
				{
					$value = $currentActivity['Properties'][self::PARAM_BLOCKS] ?? null;

					return is_array($value) ? Json::encode($value) : $value;
				},
			],
		];
	}

	public function execute(): int
	{
		if (empty($this->{self::PARAM_BLOCKS}))
		{
			$this->trackError(Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCKS_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		$userId = $this->getUserIdOnExecute();
		if (empty($userId))
		{
			$this->trackError(Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_NO_STARTER_USER'));

			return CBPActivityExecutionStatus::Closed;
		}

		$parsingErrors = [];
		$blocks = self::validateAndParseBlocks($this->{self::PARAM_BLOCKS}, $parsingErrors);
		if (!empty($parsingErrors))
		{
			$messages = array_map(static fn($error) => $error[self::ERROR_MESSAGE] ?? null, $parsingErrors);
			$this->trackError(implode(" \n", $messages));

			return CBPActivityExecutionStatus::Closed;
		}

		if ($blocks === null)
		{
			$this->trackError(Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCKS_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		$this->sendCurrentData($userId, $blocks);
		$this->subscribe($this);

		return CBPActivityExecutionStatus::Executing;
	}

	public function cancel(): int
	{
		$this->unsubscribe($this);

		return CBPActivityExecutionStatus::Closed;
	}

	private function sendPush(
		int $userId,
		BlockCollection $blocks,
		?string $templateName = null,
		?string $templateDescription = null,
	)
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		(new PushWorker())
			->send(
				self::PUSH_COMMAND,
				[
					'blocks' => $blocks->toArray(),
					'templateId' => $this->getWorkflowTemplateId(),
					'instanceId' => $this->workflow->getInstanceId(),
					'templateName' => $templateName,
					'templateDescription' => $templateDescription,
				],
				[$userId],
			)
		;
	}

	private function sendCurrentData(
		int $userId,
		BlockCollection $blocks,
	): void
	{
		$model = $this->getTemplateNameAndDescriptionModel();
		$blocksWithValues = $this->appendConstantValuesToBlocks($blocks);

		$this->sendCurrentDataEvent(
			blocks: $blocksWithValues,
			templateName: $model?->getName(),
			templateDescription: $model?->getDescription(),
		);

		$this->sendPush(
			userId: $userId,
			blocks: $blocksWithValues,
			templateName: $model?->getName(),
			templateDescription: $model?->getDescription(),
		);
	}

	public function subscribe(IBPActivityExternalEventListener $eventHandler): void
	{
		$schedulerService = $this->workflow->getService('SchedulerService');
		foreach ($this->getListenEvents() as $event)
		{
			$schedulerService->subscribeOnEvent(
				$this->workflow->getInstanceId(),
				$this->name,
				$event->getModuleId(),
				$event->getEventType(),
				$this->workflow->getInstanceId(),
			);
		}

		$expiresAt = time() + self::EXPIRES_IN;
		$this->subscriptionId = (int)$schedulerService->subscribeOnTime($this->workflow->getInstanceId(), $this->name, $expiresAt);

		$this->workflow->addEventHandler($this->name, $eventHandler);
	}

	public function unsubscribe(IBPActivityExternalEventListener $eventHandler): void
	{
		$schedulerService = $this->workflow->getService('SchedulerService');
		foreach ($this->getListenEvents() as $event)
		{
			$schedulerService->unSubscribeOnEvent(
				$this->workflow->getInstanceId(),
				$this->name,
				$event->getModuleId(),
				$event->getEventType(),
				$this->workflow->getInstanceId(),
			);
		}

		$this->workflow->removeEventHandler($this->name, $eventHandler);
		if ($this->subscriptionId > 0)
		{
			$schedulerService->unSubscribeOnTime($this->subscriptionId);
		}
	}

	public function onExternalEvent($arEventParameters = []): void
	{
		if ($this->executionStatus === CBPActivityExecutionStatus::Closed)
		{
			return;
		}

		if (($arEventParameters['SchedulerService'] ?? null) === 'OnAgent')
		{
			$this->unsubscribe($this);
			$this->workflow->terminate();

			return;
		}

		$eventName = $arEventParameters['eventName'] ?? '';
		if (!in_array($eventName, $this->getListenEventNames(), true))
		{
			return;
		}

		$continueWorkflow = match ($eventName)
		{
			SetupTemplateUserInputEvent::EVENT_NAME => $this->handleUserInputEvent($arEventParameters),
			default => false,
		};

		if (!$continueWorkflow)
		{
			return;
		}

		$this->unsubscribe($this);
		$this->workflow->closeActivity($this);
	}

	private function setConstants(array $constantValues, BlockCollection $blocks): SetConstantsResponse
	{
		[$preparedValues, $allTempFileIds] = $this->getFileConstantsFilesIds($constantValues, $blocks);

		$result = (new WorkflowTemplateService())
			->setConstants(
				new SetConstantsRequest(
					templateId: $this->getWorkflowTemplateId(),
					requestConstants: $this->appendOtherTemplateConstantsDefaults($preparedValues),
					complexDocumentType: $this->getDocumentType(),
					userId: $this->getUserIdOnExecute(),
				)
			);

		if ($result->isSuccess())
		{
			$this->makeTempFilesPersistent($allTempFileIds);
		}

		return $result;
	}

	private function sendValidationEvent(?ErrorCollection $errors = null): void
	{
		(new SetupTemplateValidationEvent())
			->setTemplateId($this->getWorkflowTemplateId())
			->setUserId($this->getUserIdOnExecute())
			->setErrors($errors)
			->send()
		;
	}

	/**
	 * @return list<Event>
	 */
	private function getListenEvents(): array
	{
		return [
			new SetupTemplateUserInputEvent(),
		];
	}

	/**
	 * @return list<string>
	 */
	private function getListenEventNames(): array
	{
		return array_map(
			static fn(Event $event): string => $event->getEventType(),
			$this->getListenEvents(),
		);
	}

	private function getUserIdOnExecute(): int
	{
		return (int)$this->workflow->getStartedBy();
	}

	protected static function validateAndParseBlocks(string|array|null $inputBlocks, array &$errors): ?BlockCollection
	{
		if (empty($inputBlocks) || (!is_string($inputBlocks) && !is_array($inputBlocks)))
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCKS_EMPTY'),
				self::ERROR_CODE_INVALID_STRUCTURE,
			);

			return null;
		}

		if (is_string($inputBlocks) && !Json::validate($inputBlocks))
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCKS_EMPTY'),
				self::ERROR_CODE_INVALID_STRUCTURE,
			);

			return null;
		}

		$arrayBlocks = is_string($inputBlocks) ? Json::decode($inputBlocks) : $inputBlocks;

		if (empty($arrayBlocks) || !is_array($arrayBlocks))
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCKS_EMPTY'),
				self::ERROR_CODE_INVALID_STRUCTURE,
			);

			return null;
		}

		$blockErrors = [];
		$collection = new BlockCollection();

		foreach ($arrayBlocks as $blockIdx => $arrayBlock)
		{
			$blockPosition = $blockIdx + 1;

			if (
				!is_array($arrayBlock)
				|| !array_key_exists(self::PARAM_BLOCK_ITEMS, $arrayBlock)
				|| empty($arrayBlock[self::PARAM_BLOCK_ITEMS])
				|| !is_array($arrayBlock[self::PARAM_BLOCK_ITEMS])
			)
			{
				$blockErrors[] = self::makeValidationError(
					Loc::getMessage(
						'BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCK_ITEMS_EMPTY',
						['#blockPosition#' => $blockPosition],
					)
				);

				continue;
			}

			$itemErrors = [];
			$itemCollection = self::validateAndParseBlockItems(
				$arrayBlock[self::PARAM_BLOCK_ITEMS],
				$blockPosition,
				$itemErrors
			);

			if (!empty($itemErrors))
			{
				$blockErrors = array_merge($blockErrors, $itemErrors);

				continue;
			}

			$block = new Block($itemCollection);
			$collection->add($block);
		}

		if (!empty($blockErrors))
		{
			$errors = array_merge($errors, $blockErrors);

			return null;
		}

		return $collection;
	}

	protected static function validateAndParseBlockItems(
		array $arrayItems,
		int $blockPosition,
		array &$errors,
	): ItemCollection
	{
		$itemCollection = new ItemCollection();

		foreach ($arrayItems as $itemIdx => $arrayItem)
		{
			$itemPosition = $itemIdx + 1;

			if (
				!is_array($arrayItem)
				|| !array_key_exists(self::PARAM_BLOCK_ITEMS_ITEM_TYPE, $arrayItem)
				|| empty($arrayItem[self::PARAM_BLOCK_ITEMS_ITEM_TYPE])
			)
			{
				$errors[] = self::makeValidationError(
					Loc::getMessage(
						'BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCK_ITEMS_ITEM_TYPE_EMPTY',
						[
							'#itemPosition#' => $itemPosition,
							'#blockPosition#' => $blockPosition,
						],
					)
				);

				continue;
			}

			$itemErrors = [];

			$item = match ($arrayItem[self::PARAM_BLOCK_ITEMS_ITEM_TYPE])
			{
				ItemType::Title->value => self::validateTitle($arrayItem, $blockPosition, $itemPosition, $itemErrors),
				ItemType::Description->value => self::validateDescription($arrayItem, $blockPosition, $itemPosition, $itemErrors),
				ItemType::Delimiter->value => self::validateDelimiter($arrayItem, $blockPosition, $itemPosition, $itemErrors),
				ItemType::Constant->value => self::validateConstant($arrayItem, $blockPosition, $itemPosition, $itemErrors),
				ItemType::TitleWithIcon->value => self::validateTitleWithIcon($arrayItem, $blockPosition, $itemPosition, $itemErrors),
				default => self::invalidTypeError($blockPosition, $itemPosition, $itemErrors),
			};

			if (is_null($item))
			{
				$errors = array_merge($errors, $itemErrors);

				continue;
			}

			$itemCollection->add($item);
		}

		return $itemCollection;
	}

	protected static function requireString(
		array $item,
		string $key,
		int $blockPosition,
		int $itemPosition,
		string $labelKey,
		array &$errors,
	): void
	{
		if (!array_key_exists($key, $item) || is_null($item[$key]) || $item[$key] === '')
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
				'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_REQUIRE',
					[
						'#name#' => Loc::getMessage($labelKey),
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					],
				)
			);
		}
		elseif (!is_string($item[$key]))
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_IS_STRING',
					[
						'#name#' => Loc::getMessage($labelKey),
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);
		}
	}

	protected static function validateConstant(
		array $arrayItem,
		int $blockPosition,
		int $itemPosition,
		array &$errors,
	): ?Item
	{
		$props = [
			'id' => 'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_MENU',
			'name' => 'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_NAME',
			'constantType' => 'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_TYPE',
		];

		foreach ($props as $key => $label)
		{
			self::requireString(
				$arrayItem,
				$key,
				$blockPosition,
				$itemPosition,
				$label,
				$errors
			);
		}

		if (
			array_key_exists('description', $arrayItem)
			&& !is_null($arrayItem['description'])
			&& $arrayItem['description'] !== ''
			&& !is_string($arrayItem['description'])
		)
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_IS_STRING',
					[
						'#name#' => Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_DESCRIPTION'),
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);
		}

		$propBools = [
			'multiple' => 'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_MULTIPLE',
			'required' => 'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_REQUIRED',
		];

		foreach ($propBools as $key => $label)
		{
			if (
				array_key_exists($key, $arrayItem)
				&& !is_null($arrayItem[$key])
				&& !is_bool($arrayItem[$key])
			)
			{
				$errors[] = self::makeValidationError(
					Loc::getMessage(
						'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_IS_BOOL',
						[
							'#name#' => Loc::getMessage($label),
							'#itemPosition#' => $itemPosition,
							'#blockPosition#' => $blockPosition,
						]
					),
				);
			}
		}

		if (
			array_key_exists('options', $arrayItem)
			&& !empty($arrayItem['options'])
			&& !is_array($arrayItem['options'])
		)
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_IS_ARRAY',
					[
						'#name#' => Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_OPTIONS'),
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);
		}

		if (!empty($errors))
		{
			return null;
		}

		return new Constant(
			$arrayItem['id'],
			$arrayItem['name'],
			$arrayItem['constantType'],
			$arrayItem['description'] ?? '',
			$arrayItem['multiple'] ?? false,
			$arrayItem['required'] ?? false,
			$arrayItem['options'] ?? [],
			(string)($arrayItem['default'] ?? ''),
		);
	}

	protected static function validateDelimiter(
		array $arrayItem,
		int $blockPosition,
		int $itemPosition,
		array &$errors,
	): ?Item
	{
		self::requireString(
			$arrayItem,
			'delimiterType',
			$blockPosition,
			$itemPosition,
			'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_DELIMITER_ITEM',
			$errors
		);

		if (
			array_key_exists('delimiterType', $arrayItem)
			&& is_string($arrayItem['delimiterType'])
			&& is_null(DelimiterType::tryFrom($arrayItem['delimiterType']))
		)
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_ENUM',
					[
						'#name#' => Loc::getMessage(
							'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_DELIMITER_ITEM'
						),
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);
		}

		return
			empty($errors)
				? new Delimiter(DelimiterType::from($arrayItem['delimiterType']))
				: null
		;
	}

	protected static function validateDescription(
		array $arrayItem,
		int $blockPosition,
		int $itemPosition,
		array &$errors,
	): ?Item
	{
		self::requireString(
			$arrayItem,
			'text',
			$blockPosition,
			$itemPosition,
			'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_DESCRIPTION_ITEM',
			$errors
		);

		return
			empty($errors)
				? new Description($arrayItem['text'])
				: null
		;
	}

	protected static function validateTitle(
		array $arrayItem,
		int $blockPosition,
		int $itemPosition,
		array &$errors,
	):  ?Item
	{
		self::requireString(
			$arrayItem,
			'text',
			$blockPosition,
			$itemPosition,
			'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_TITLE_ITEM',
			$errors
		);

		return
			empty($errors)
				? new Title($arrayItem['text'])
				: null
		;
	}

	protected static function invalidTypeError(int $blockPosition, int $itemPosition, array &$errors): ?Item
	{
		$errors[] = self::makeValidationError(
			Loc::getMessage(
				'BIZPROC_SETUP_TEMPLATE_ACTIVITY_PROPERTY_BLOCK_ITEMS_ITEM_TYPE_UNKNOWN',
				[
					'#itemPosition#' => $itemPosition,
					'#blockPosition#' => $blockPosition,
				]
			),
			self::ERROR_CODE_UNKNOWN_ITEM_TYPE,
		);

		return null;
	}

	private static function makeValidationError(?string $message, string $code = self::ERROR_CODE_NOT_EXIST): array
	{
		return [
			self::ERROR_MESSAGE => $message,
			self::ERROR_CODE => $code,
		];
	}

	private function appendOtherTemplateConstantsDefaults(array $constantValues): array
	{
		$constants = (array)\CBPWorkflowTemplateLoader::getTemplateConstants($this->getWorkflowTemplateId());
		$allConstantValues = [];
		foreach ($constants as $constantId => $constant)
		{
			if (array_key_exists($constantId, $constantValues))
			{
				$allConstantValues[$constantId] = $constantValues[$constantId];
			}
			else
			{
				$allConstantValues[$constantId] = $constant['Default'] ?? null;
			}
		}

		return $allConstantValues;
	}

	private function validateConstants(array $constants, int $userId, BlockCollection $blocks): ErrorCollection
	{
		$errors = [];
		$constantIdList = [];

		foreach ($blocks as $block)
		{
			foreach ($block->items as $item)
			{
				if (!$item instanceof Constant)
				{
					continue;
				}

				$constantIdList[] = $item->id;
				$constantValue = $constants[$item->id] ?? null;
				$customDataError = [self::ERROR_CONSTANT => $item->id];

				if ($item->required === true && empty($constantValue))
				{
					$errors[] = new Error(
						Loc::getMessage('BIZPROC_CONSTANT_EMPTY_PROP', ['#PROPERTY#' => $item->name]),
						self::ERROR_CODE_NOT_EXIST,
						$customDataError
					);

					continue;
				}

				$typeClass = CBPRuntime::getRuntime()
					->getDocumentService()
					->getTypeClass($this->getDocumentType(), $item->constantType)
				;

				if (is_null($typeClass))
				{
					$errors[] = new Error(
						Loc::getMessage('BIZPROC_CONSTANT_FIELD_TYPE_UNKNOWN'),
						self::ERROR_CODE_UNKNOWN_FIELD_TYPE,
						$customDataError
					);

					continue;
				}

				$fieldType = new FieldType($item->toFieldTypeArray(), $this->getDocumentType(), $typeClass);

				$fileValidationResult = $this->validateFileConstant($item, $constantValue);
				if (!$fileValidationResult->isSuccess())
				{
					$errors = array_merge($errors, $fileValidationResult->getErrors());
				}

				$itemErrors = [];
				$value = $fieldType->extractValue(['Field' => $item->id], $constants, $itemErrors);

				foreach ($itemErrors as $error)
				{
					$errors[] = new Error($error[self::ERROR_MESSAGE], $error[self::ERROR_CODE], $customDataError);
				}

				$accessValidationResult = (new AccessValidationService())
					->isUserHasAccessToValue($fieldType->getTypeClass(), $userId, $value)
				;
				$errors = array_merge($errors, $accessValidationResult->getErrorCollection()->getValues());
			}
		}

		$diff = array_diff(array_keys($constants), $constantIdList);

		if (!empty($diff))
		{
			$errors[] = new Error(
				Loc::getMessage('BIZPROC_CONSTANT_UNKNOWN_ID', ['#constantIdList#' => implode(', ', $diff)]),
				self::ERROR_CODE_NOT_EXIST
			);
		}

		return $this->toErrorCollection($errors);
	}

	private function toErrorCollection(array $errors): ErrorCollection
	{
		$errorCollection = new ErrorCollection();

		foreach ($errors as $error)
		{
			if (!$error instanceof \Bitrix\Main\Error)
			{
				$error = new \Bitrix\Main\Error($error[self::ERROR_MESSAGE], $error[self::ERROR_CODE]);
			}

			$errorCollection->setError($error);
		}

		return $errorCollection;
	}

	/**
	 * @return array<string, string> [type => name,...]
	 */
	private static function getAllowedConstantTypes(?array $documentType): array
	{
		$documentService = CBPRuntime::getRuntime()->getDocumentService();
		$types = $documentService->GetDocumentFieldTypes($documentType);
		$typeNames = array_map(
			static fn(array $field): string => $field['Name'] ?? '',
			$types,
		);
		$allowedTypes = [
			FieldType::INT,
			FieldType::STRING,
			FieldType::TEXT,
			FieldType::SELECT,
			FieldType::USER,
			RagKnowledgeBaseType::getType(),
			ProjectType::getType(),
			FieldType::FILE,
			FieldType::TIME,
		];

		return array_filter(
			$typeNames,
			static fn(?string $v, string $k): bool => !empty($v) && in_array($k, $allowedTypes, true),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	private function appendConstantValuesToBlocks(BlockCollection $blocks): BlockCollection
	{
		$patchedCollection = new BlockCollection();
		foreach ($blocks as $block)
		{
			$patchedCollection->add(new Block($this->appendConstantValuesToItems($block->items)));
		}

		return $patchedCollection;
	}

	private function appendConstantValuesToItems(ItemCollection $items): ItemCollection
	{
		$patchedItems = new ItemCollection();
		foreach ($items as $item)
		{
			$patchedItems->add($this->appendConstantValuesToItem($item));
		}

		return $patchedItems;
	}

	private function appendConstantValuesToItem(Item $item): Item
	{
		if (!$item instanceof Constant)
		{
			return $item;
		}

		$value = $this->getConstant($item->id);
		if (is_scalar($value))
		{
			$value = (string)$value;
		}

		if (empty($value) || (!is_string($value) && !is_array($value)))
		{
			return $item;
		}

		return new Constant(
			id: $item->id,
			name: $item->name,
			constantType: $item->constantType,
			description: $item->description,
			multiple: $item->multiple,
			required: $item->required,
			options: $item->options,
			default: $value,
		);
	}

	private function getTemplateNameAndDescriptionModel(): ?EO_WorkflowTemplate
	{
		return WorkflowTemplateTable::query()
			->where('ID', $this->workflow->getTemplateId())
			->setLimit(1)
			->setSelect(['NAME', 'DESCRIPTION'])
			->fetchObject()
		;
	}

	private static function validateTitleWithIcon(
		array $arrayItem,
		int $blockPosition,
		int $itemPosition,
		array &$errors,
	): ?TitleWithIcon
	{
		self::requireString(
			$arrayItem,
			'text',
			$blockPosition,
			$itemPosition,
			'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_TITLE_ITEM',
			$errors
		);

		self::requireString(
			$arrayItem,
			'icon',
			$blockPosition,
			$itemPosition,
			'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_ICON_ITEM',
			$errors
		);

		$icon = $arrayItem['icon'] ?? '';
		if (!in_array($icon, TitleWithIcon::getAllowedIconValues(), true))
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_ENUM',
					[
						'#name#' => Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_ICON_ITEM'),
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);
		}

		return empty($errors) ? new TitleWithIcon($arrayItem['text'], $icon) : null;
	}
	private function validateFileConstant(Constant $constant, mixed $value): \Bitrix\Main\Result
	{
		if ($constant->constantType !== FieldType::FILE)
		{
			return new \Bitrix\Main\Result();
		}

		if (!Loader::includeModule('ui'))
		{
			return (new \Bitrix\Main\Result())
				->addError(Error::fromCode(Error::MODULE_NOT_INSTALLED, ['moduleName' => 'ui']))
				;
		}

		[$tempFiles, $persistentFiles] = $this->splitFiles($value);

		$tempFilesValidationResult = $this->validateUploadedFiles($tempFiles);
		$persistentValidationResult = $this->validatePersistentFiles($persistentFiles, $constant->id);

		return (new \Bitrix\Main\Result())
			->addErrors($tempFilesValidationResult->getErrors())
			->addErrors($persistentValidationResult->getErrors())
			;
	}

	private function splitFiles(mixed $value): array
	{
		$arrayValue = is_array($value) ? $value : [$value];

		return UploaderHelper::splitFiles($arrayValue);
	}

	private function getFileConstantsFilesIds(array $constantValues, BlockCollection $blocks): array
	{
		$allTempFileIds = [];
		if (!Loader::includeModule('ui'))
		{
			return [$constantValues, $allTempFileIds];
		}

		$uploader = $this->getConstantsUploader();

		foreach ($constantValues as $constantId => $constantValue)
		{
			if (empty($constantValue))
			{
				continue;
			}

			$constant = $this->getConstantById($blocks, $constantId);
			if ($constant?->constantType !== FieldType::FILE)
			{
				continue;
			}

			[$tempFiles, $persistentFiles] = $this->splitFiles($constantValue);
			$allTempFileIds = array_merge($allTempFileIds, $tempFiles);
			$pendingFiles = $uploader->getPendingFiles($tempFiles);
			$ids = array_merge($persistentFiles, $pendingFiles->getFileIds());
			$constantValues[$constantId] = array_map(static fn($id) => \CBPDocument::signParameters([$id]), $ids);
		}

		return [$constantValues, $allTempFileIds];
	}

	private function getConstantById(BlockCollection $blocks, string $id): ?Constant
	{
		foreach ($blocks as $block)
		{
			foreach ($block->items as $item)
			{
				if ($item instanceof Constant && $item->id === $id)
				{
					return $item;
				}
			}
		}

		return null;
	}

	private function getConstantsUploader(): Uploader
	{
		$controller = new SetupTemplateUploaderController([
			SetupTemplateUploaderController::OPTION_TEMPLATE_ID => $this->workflow->getTemplateId(),
		]);

		return new Uploader($controller);
	}

	private function makeTempFilesPersistent(array $tempFileIds): void
	{
		if (!Loader::includeModule('ui') || empty($tempFileIds))
		{
			return;
		}

		$uploader = $this->getConstantsUploader();
		$pendingFiles = $uploader->getPendingFiles($tempFileIds);
		$pendingFiles->makePersistent();
	}

	private function validateUploadedFiles(array $tempFiles): \Bitrix\Main\Result
	{
		$uploader = $this->getConstantsUploader();
		$pendingFiles = $uploader->getPendingFiles($tempFiles);

		return UploaderHelper::validatePendingFiles($pendingFiles, $tempFiles);
	}

	private function validatePersistentFiles(array $persistentFiles, string $constantId): \Bitrix\Main\Result
	{
		$currentFileIds = $this->getConstantCurrentValueAsIntArray($constantId);
		foreach ($persistentFiles as $fileId)
		{
			if (!in_array((int)$fileId, $currentFileIds, true))
			{
				return (new \Bitrix\Main\Result())
					->addError(ErrorMessage::INVALID_FILE->getError())
					;
			}
		}

		return new \Bitrix\Main\Result();
	}

	/**
	 * @param string $constantId
	 *
	 * @return list<int>
	 */
	private function getConstantCurrentValueAsIntArray(string $constantId): array
	{
		$currentFileIds = $this->getConstantType($constantId)['Default'] ?? [];
		$currentFileIds = is_array($currentFileIds) ? $currentFileIds : [$currentFileIds];
		$currentFileIds = array_map(static fn($id) => (int)$id, $currentFileIds);

		return array_filter($currentFileIds);
	}

	private function handleUserInputEvent(array $eventParameters): bool
	{
		$instanceId = $eventParameters[0] ?? null;
		$userId = $eventParameters[1] ?? null;
		$templateId = $eventParameters[2] ?? null;
		$constantValues = (array)($eventParameters[3] ?? []);
		if (
			$templateId !== $this->getWorkflowTemplateId()
			|| $userId !== $this->getUserIdOnExecute()
			|| $instanceId !== $this->workflow->getInstanceId()
		)
		{
			return false;
		}

		$errors = [];
		$blocks = $this->validateAndParseBlocks($this->{self::PARAM_BLOCKS}, $errors);
		if (!empty($errors))
		{
			$this->sendValidationEvent($this->toErrorCollection($errors));

			return false;
		}

		$errors = $this->validateConstants($constantValues, $userId, $blocks);
		if (!$errors->isEmpty())
		{
			$this->sendValidationEvent($errors);

			return false;
		}

		$result = $this->setConstants($constantValues, $blocks);
		$this->sendValidationEvent($result->getErrorCollection());

		return $result->isSuccess();
	}

	private function sendCurrentDataEvent(
		BlockCollection $blocks,
		?string $templateName = null,
		?string $templateDescription = null,
	): void
	{
		(new SetupTemplateCurrentDataEvent())
			->setTemplateId($this->getWorkflowTemplateId())
			->setUserId($this->getUserIdOnExecute())
			->setInstanceId($this->workflow->getInstanceId())
			->setBlocks($blocks)
			->setTemplateName($templateName)
			->setTemplateDescription($templateDescription)
			->send()
		;
	}
}
