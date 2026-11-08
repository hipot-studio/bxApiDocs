<?php

use Bitrix\Bizproc\Public\Activity\Interface\ActivityContentBlockProviderInterface;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SetConstantsRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateService\SetConstantsResponse;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\BaseType\ValueRule;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\FileUploader\SetupTemplateUploaderController;
use Bitrix\Bizproc\Integration\Push\PushWorker;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Block;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\BlockCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Constant;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ConstantConfiguration;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Delimiter;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\DelimiterType;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Description;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\EntitySelector\SelectorProvider;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Item;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ItemCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ItemType;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\Title;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\TitleWithIcon;
use Bitrix\Bizproc\Internal\Event\SetupTemplateCurrentDataEvent;
use Bitrix\Bizproc\Internal\Event\SetupTemplateUserInputEvent;
use Bitrix\Bizproc\Internal\Event\SetupTemplateValidationEvent;
use Bitrix\Bizproc\Internal\Integration\BI\Dashboard\DocumentFieldTypes\BIDashboardType;
use Bitrix\Bizproc\Internal\Integration\Rag\DocumentFieldTypes\RagKnowledgeBaseType;
use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectFieldStateResolver;
use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectType;
use Bitrix\Bizproc\Internal\Integration\UI\UploaderHelper;
use Bitrix\Bizproc\Internal\Service\DocumentField\AccessValidationService;
use Bitrix\Bizproc\Internal\Service\Pilot\SettingsFreezeGate;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\DescriptionMarkup;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateConstantsService;
use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupBlocksParseResult;
use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupConstantDeclaration;
use Bitrix\Bizproc\Public\Entity\Activity\SetupTemplateActivity\SetupParseError;
use Bitrix\Bizproc\Workflow\Template\Entity\EO_WorkflowTemplate;
use Bitrix\Bizproc\WorkflowTemplateTable;
use Bitrix\Main\DI\ServiceLocator;
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

class CBPSetupTemplateActivity extends CBPActivity implements IBPEventActivity, IBPActivityExternalEventListener, ActivityContentBlockProviderInterface
{
	private const PARAM_BLOCKS = 'blocks';
	private const PARAM_BLOCK_ITEMS = 'items';
	private const PARAM_BLOCK_ITEMS_ITEM_TYPE = 'itemType';
	private const PUSH_COMMAND = 'setupTemplateActivityBlocks';
	private const ERROR_MESSAGE = 'message';
	private const ERROR_CODE = 'code';
	private const ERROR_BLOCK_POSITION = 'blockPosition';
	private const ERROR_ITEM_POSITION = 'itemPosition';
	private const ERROR_CONSTANT = 'constant';
	private const ERROR_CODE_NOT_EXIST = 'NotExist';
	private const ERROR_CODE_INVALID_STRUCTURE = 'InvalidStructJson';
	private const ERROR_CODE_UNKNOWN_ITEM_TYPE = 'UnknownItemType';
	private const ERROR_CODE_UNKNOWN_FIELD_TYPE = 'UnknownFieldType';
	private const EXPIRES_IN = 24 * 60 * 60;
	private const CONSTANT_SETTINGS_ENTITYSELECTOR_SELECTOR_ID = 'selectorId';
	private const CONSTANT_SETTINGS_ENTITYSELECTOR_SELECTOR = 'selector';
	private const VALUE_RULE_BOUND_MAX_DIGITS = 10;
	private const PARSED_BLOCKS_MEMO_LIMIT = 8;

	/** Bounds of a description the form can render as markup, see withoutUnsupportedMarkup(). */
	private const DESCRIPTION_MAX_LENGTH = 20000;
	private const DESCRIPTION_MAX_TAG_COUNT = 5000;
	private const DESCRIPTION_MAX_NESTING_DEPTH = 50;

	/**
	 * The tokenizer of the form reads a tag as the name and then everything up to the first closing
	 * bracket, so counting has to read it the same way: what the tokenizer opens is what costs.
	 */
	private const DESCRIPTION_TAG_PATTERN = '#\\[(?P<closing>/?)(?P<name>[a-z0-9_]+|\\*)[^\\]]*\\]#iu';

	private const DESCRIPTION_LIST_TAG = 'list';
	private const DESCRIPTION_LIST_ITEM_TAG = '*';
	private int $subscriptionId = 0;

	/** @var array<string, array{collection: ?BlockCollection, errors: array}> parsed blocks by payload */
	private static array $parsedBlocksMemo = [];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_BLOCKS => null,
		];
	}

	public static function getContentBlock(array $properties, ?\Bitrix\Bizproc\Activity\Dto\ContentBlockContext $context = null): ?\Bitrix\Bizproc\Activity\Dto\ContentBlock
	{
		$constantsCount = count(
			self::extractBlockItemsByType($properties[self::PARAM_BLOCKS] ?? null, ItemType::Constant)
		);
		if ($constantsCount === 0)
		{
			return null;
		}

		return new \Bitrix\Bizproc\Activity\Dto\ContentBlock(
			(string)Loc::getMessage(
				'BIZPROC_SETUP_TEMPLATE_ACTIVITY_CONTENT_BLOCK_CONSTANTS',
				['#count#' => $constantsCount],
			),
		);
	}

	/**
	 * Leniently extracts raw block items of the given type from the blocks property value.
	 *
	 * Unlike validateAndParseBlocks(), performs no validation on purpose: the canvas label must
	 * degrade gracefully on partially invalid data and stay cheap, since it runs per node on every
	 * diagram render.
	 *
	 * @return list<array>
	 */
	private static function extractBlockItemsByType(mixed $rawBlocks, ItemType $type): array
	{
		try
		{
			$blocks = is_string($rawBlocks) ? Json::decode($rawBlocks) : $rawBlocks;
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			return [];
		}

		if (!is_array($blocks))
		{
			return [];
		}

		$items = [];
		foreach ($blocks as $block)
		{
			if (!is_array($block))
			{
				continue;
			}

			foreach ((array)($block[self::PARAM_BLOCK_ITEMS] ?? []) as $item)
			{
				if (
					is_array($item)
					&& ($item[self::PARAM_BLOCK_ITEMS_ITEM_TYPE] ?? null) === $type->value
				)
				{
					$items[] = $item;
				}
			}
		}

		return $items;
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
			'constantConfigurationList' => self::getConstantConfigurationList($documentType),
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

	public static function getAiPassthroughSettingNames(array $documentType): array
	{
		// Persisted but not typable via getPropertiesMap() (which maps only `blocks`, as CUSTOM):
		// the blocks payload plus the setup `user` (starter expression) and `description` settings.
		return ['blocks', 'user', 'description'];
	}

	public function execute(): int
	{
		// the values belong to the template and not to the employee, so a frozen template opens the form
		// for nobody: the step runs on the values already saved, the same way for everyone
		if ((new SettingsFreezeGate())->isFrozen($this->getWorkflowTemplateId()))
		{
			return CBPActivityExecutionStatus::Closed;
		}

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
		$blocksWithValues = $this->appendConstantInfoToBlocks($blocks, $userId);
		// the setup form renders the description with the BBCode formatter, so the stored markup goes as is
		$templateDescription = self::withoutUnsupportedMarkup($model?->getDescription());

		$this->sendCurrentDataEvent(
			blocks: $blocksWithValues,
			templateName: $model?->getName(),
			templateDescription: $templateDescription,
		);

		$this->sendPush(
			userId: $userId,
			blocks: $blocksWithValues,
			templateName: $model?->getName(),
			templateDescription: $templateDescription,
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

		$result = (new SetupTemplateConstantsService())
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

	/**
	 * Builds a setup-block subset that keeps only the constants with the given codes while
	 * preserving the structural items (title/description/delimiter/... any non-Constant item)
	 * of every block that retains at least one requested constant. Blocks without any requested
	 * constant are dropped entirely, and item order inside a kept block is preserved.
	 *
	 * Two callers: the AI-agent upgrade fill scenario renders only the NEW required constants
	 * of the reference template in the existing setup-template UI, without starting a setup
	 * workflow, and the save of a template checks the stored values of the constants whose
	 * value rule it changes. Structural items are kept for the UI, which shows section headers
	 * and hints instead of bare fields; the check of the values ignores them. Parsing is
	 * delegated to validateAndParseBlocks() so the block/constant contract is not duplicated.
	 *
	 * @param string|array|null $inputBlocks Raw blocks (JSON string or array) from the
	 *   'blocks' property of a SetupTemplateActivity.
	 * @param list<string> $constantCodes Constant codes to keep.
	 * @return array Filtered blocks as an array (empty when nothing matches).
	 */
	public static function filterBlocksByConstantCodes(string|array|null $inputBlocks, array $constantCodes): array
	{
		$errors = [];
		$collection = self::validateAndParseBlocks($inputBlocks, $errors);
		if ($collection === null || !empty($errors))
		{
			return [];
		}

		$keep = array_fill_keys(array_map('strval', $constantCodes), true);

		$filtered = new BlockCollection();
		foreach ($collection as $block)
		{
			$items = new ItemCollection();
			$hasRequestedConstant = false;
			foreach ($block->items as $item)
			{
				if ($item instanceof Constant)
				{
					if (isset($keep[$item->id]))
					{
						$items->add($item);
						$hasRequestedConstant = true;
					}

					continue;
				}

				// Keep structural items (title/description/delimiter/...) so kept blocks
				// render with their headers and hints instead of bare constant fields.
				$items->add($item);
			}

			if ($hasRequestedConstant)
			{
				$filtered->add(new Block($items));
			}
		}

		return $filtered->toArray();
	}

	/**
	 * @return list<string>|null
	 */
	public static function collectConstantCodes(string|array|null $inputBlocks): ?array
	{
		$errors = [];
		$blocks = self::validateAndParseBlocks($inputBlocks, $errors);
		if ($blocks === null || !empty($errors))
		{
			return null;
		}

		$constantCodes = [];
		foreach ($blocks as $block)
		{
			foreach ($block->items as $item)
			{
				if ($item instanceof Constant)
				{
					$constantCodes[] = $item->id;
				}
			}
		}

		return array_values(array_unique($constantCodes));
	}

	/**
	 * Value rules the setup constants of the given raw blocks declare, keyed by constant code. A constant
	 * without a rule is absent from the result, and null means the blocks were refused - the validation of
	 * the tree reports that on its own.
	 *
	 * @return array<string, array>|null
	 */
	public static function collectConstantValueRules(string|array|null $inputBlocks): ?array
	{
		$errors = [];
		$blocks = self::validateAndParseBlocks($inputBlocks, $errors);
		if ($blocks === null || !empty($errors))
		{
			return null;
		}

		$valueRules = [];
		foreach ($blocks as $block)
		{
			foreach ($block->items as $item)
			{
				if (!$item instanceof Constant)
				{
					continue;
				}

				$rule = $item->settings[ValueRule::SETTINGS_KEY] ?? null;
				if (is_array($rule) && !empty($rule))
				{
					$valueRules[$item->id] = $rule;
				}
			}
		}

		return $valueRules;
	}

	/**
	 * Instance-free field-definition validation of submitted constant values against setup blocks.
	 *
	 * Mirrors the field-type/format portion of validateConstants() but without the running-instance
	 * concerns (file uploader, access checks): it only decides which submitted values are invalid
	 * for their setup-block field definition. Used by the AI-agent upgrade pre-commit re-validation
	 * (P3.T2) so an invalid value re-shows the review master instead of being written and only
	 * caught by the post-apply best-effort fill(). Emptiness is not reported here — a missing
	 * required value is the caller's separate "still missing required" check. When a field type
	 * cannot be resolved without an instance (rare) the constant is left to that best-effort fill()
	 * rather than false-blocking an otherwise valid upgrade.
	 *
	 * @param array $documentType Complex document type the constants belong to.
	 * @param string|array|null $inputBlocks Raw setup blocks (JSON string or array).
	 * @param array<string, mixed> $constantValues Submitted values keyed by constant code.
	 * @return list<string> Codes of submitted values that fail their field-type validation.
	 */
	public static function collectInvalidConstantCodes(
		array $documentType,
		string|array|null $inputBlocks,
		array $constantValues,
	): array
	{
		if ($constantValues === [])
		{
			return [];
		}

		$errors = [];
		$blocks = self::validateAndParseBlocks($inputBlocks, $errors);
		if ($blocks === null || !empty($errors))
		{
			return [];
		}

		$documentService = CBPRuntime::getRuntime()->getDocumentService();

		$invalid = [];
		foreach ($blocks as $block)
		{
			foreach ($block->items as $item)
			{
				if (!$item instanceof Constant || !array_key_exists($item->id, $constantValues))
				{
					continue;
				}

				if (CBPHelper::isEmptyValue($constantValues[$item->id]))
				{
					continue;
				}

				$fieldType = $documentService->getFieldTypeObject($documentType, $item->toFieldTypeArray());
				if ($fieldType === null)
				{
					continue;
				}

				$itemErrors = [];
				$fieldType->extractValue(['Field' => $item->id], $constantValues, $itemErrors);
				if (!empty($itemErrors))
				{
					$invalid[] = $item->id;
				}
			}
		}

		return $invalid;
	}

	/**
	 * Instance-free public reading of a setup wizard form structure.
	 *
	 * Facade over validateAndParseBlocks(): it parses the raw blocks and converts the internal form
	 * collection into the public snapshot, so a caller outside bizproc learns what the form declares
	 * without depending on the module's internal entities. Reads only - no activity instance, no
	 * running workflow, nothing stored. On a structural error the snapshot carries error codes and
	 * positions but no messages: parse messages are localized for the wizard owner.
	 *
	 * @param array $rawBlocks Raw blocks of a SetupTemplateActivity 'blocks' property.
	 */
	public static function parseSetupBlocks(array $rawBlocks): SetupBlocksParseResult
	{
		$errors = [];
		$collection = self::validateAndParseBlocks($rawBlocks, $errors);

		if ($collection === null || !empty($errors))
		{
			// A failure must never look like a form that declares nothing, so an unreported
			// failure still travels out as one structural error.
			$parseErrors = self::toSetupParseErrors($errors)
				?: [new SetupParseError(self::ERROR_CODE_INVALID_STRUCTURE)]
			;

			return new SetupBlocksParseResult(errors: $parseErrors);
		}

		$declarations = [];
		$itemCount = 0;
		$blockPosition = 0;

		// A successful parse keeps every block and item in input order, so counting them here
		// reproduces the 1-based positions the parser reports to the wizard owner.
		foreach ($collection as $block)
		{
			++$blockPosition;
			$itemCount += count($block->items);

			$itemPosition = 0;
			foreach ($block->items as $item)
			{
				++$itemPosition;
				if ($item instanceof Constant)
				{
					$declarations[] = self::toSetupConstantDeclaration($item, $blockPosition, $itemPosition);
				}
			}
		}

		return new SetupBlocksParseResult($declarations, $itemCount);
	}

	private static function toSetupConstantDeclaration(
		Constant $constant,
		int $blockPosition,
		int $itemPosition,
	): SetupConstantDeclaration
	{
		return new SetupConstantDeclaration(
			code: $constant->id,
			name: $constant->name,
			type: $constant->constantType,
			description: $constant->description,
			multiple: $constant->multiple,
			options: $constant->options,
			settings: $constant->settings,
			default: $constant->default,
			blockPosition: $blockPosition,
			itemPosition: $itemPosition,
		);
	}

	/**
	 * @param array $errors Parse errors as makeValidationError() builds them.
	 * @return list<SetupParseError>
	 */
	private static function toSetupParseErrors(array $errors): array
	{
		$parseErrors = [];
		foreach ($errors as $error)
		{
			$parseErrors[] = new SetupParseError(
				(string)($error[self::ERROR_CODE] ?? self::ERROR_CODE_INVALID_STRUCTURE),
				$error[self::ERROR_BLOCK_POSITION] ?? null,
				$error[self::ERROR_ITEM_POSITION] ?? null,
			);
		}

		return $parseErrors;
	}

	/**
	 * Memoized by the payload: one save of a template parses the same blocks several times, and a parse is
	 * two json_decode plus the validation of every item.
	 */
	protected static function validateAndParseBlocks(string|array|null $inputBlocks, array &$errors): ?BlockCollection
	{
		$key = md5(serialize($inputBlocks));
		if (!isset(self::$parsedBlocksMemo[$key]))
		{
			if (count(self::$parsedBlocksMemo) >= self::PARSED_BLOCKS_MEMO_LIMIT)
			{
				array_shift(self::$parsedBlocksMemo);
			}

			$parsingErrors = [];
			self::$parsedBlocksMemo[$key] = [
				'collection' => self::parseBlocksPayload($inputBlocks, $parsingErrors),
				'errors' => $parsingErrors,
			];
		}

		$parsed = self::$parsedBlocksMemo[$key];
		if ($parsed['errors'])
		{
			$errors = array_merge($errors, $parsed['errors']);
		}

		return $parsed['collection'];
	}

	private static function parseBlocksPayload(string|array|null $inputBlocks, array &$errors): ?BlockCollection
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
					),
					blockPosition: $blockPosition,
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
					),
					blockPosition: $blockPosition,
					itemPosition: $itemPosition,
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
				),
				blockPosition: $blockPosition,
				itemPosition: $itemPosition,
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
				blockPosition: $blockPosition,
				itemPosition: $itemPosition,
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
				blockPosition: $blockPosition,
				itemPosition: $itemPosition,
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
					blockPosition: $blockPosition,
					itemPosition: $itemPosition,
				);
			}
		}

		if (
			array_key_exists('options', $arrayItem)
			&& !empty($arrayItem['options'])
		)
		{
			$options = $arrayItem['options'];
			$isOptionsMap = is_array($options);
			if ($isOptionsMap)
			{
				foreach ($options as $optionValue => $optionLabel)
				{
					if (!is_string($optionLabel) || trim((string)$optionValue) === '')
					{
						$isOptionsMap = false;
						break;
					}
				}
			}

			if (!$isOptionsMap)
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
					blockPosition: $blockPosition,
					itemPosition: $itemPosition,
				);
			}
		}

		if (
			array_key_exists('settings', $arrayItem)
			&& !is_null($arrayItem['settings'])
			&& !is_array($arrayItem['settings'])
		)
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_IS_ARRAY',
					[
						'#name#' => Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_SETTINGS'),
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
				blockPosition: $blockPosition,
				itemPosition: $itemPosition,
			);
		}

		$constantType = $arrayItem['constantType'] ?? null;
		if ($constantType === FieldType::ENTITYSELECTOR)
		{
			$settings = $arrayItem['settings'] ?? [];
			$settings = is_array($settings) ? $settings : [];

			$selectorProvider = ServiceLocator::getInstance()->get(SelectorProvider::class);
			$selectorId = $settings[self::CONSTANT_SETTINGS_ENTITYSELECTOR_SELECTOR_ID] ?? null;
			if (!$selectorProvider->isExists($selectorId))
			{
				$errors[] = self::makeValidationError(
					Loc::getMessage(
						'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_ENUM',
						[
							'#name#' => Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_ENTITY_SELECTOR_SELECTOR_ID'),
							'#itemPosition#' => $itemPosition,
							'#blockPosition#' => $blockPosition,
						]
					),
					blockPosition: $blockPosition,
					itemPosition: $itemPosition,
				);
			}
		}

		$constantSettings = $arrayItem['settings'] ?? [];
		if (is_array($constantSettings) && is_string($constantType) && $constantType !== '')
		{
			self::validateConstantSettings(
				$constantType,
				$constantSettings,
				$blockPosition,
				$itemPosition,
				$errors,
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
			$arrayItem['settings'] ?? [],
			self::normalizeConstantDefault($arrayItem['default'] ?? ''),
		);
	}

	/**
	 * The rule is listed per constant type, not per base type of the field, so it stays away from the types
	 * inheriting IntType and StringType.
	 */
	private static function supportsConstantValueRule(string $constantType): bool
	{
		return match ($constantType)
		{
			FieldType::INT, FieldType::STRING, FieldType::TEXT => true,
			default => false,
		};
	}

	/**
	 * The value rule of the settings only: any other key of the settings is not this validator's business,
	 * the keys inside the rule are checked by validateConstantValueRule().
	 */
	private static function validateConstantSettings(
		string $constantType,
		array $settings,
		int $blockPosition,
		int $itemPosition,
		array &$errors,
	): void
	{
		if (!array_key_exists(ValueRule::SETTINGS_KEY, $settings))
		{
			return;
		}

		// the key is refused for a type that declares no rule whatever its value stands for
		if (!self::supportsConstantValueRule($constantType))
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_VALUE_RULE_NOT_SUPPORTED',
					[
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);

			return;
		}

		$rule = $settings[ValueRule::SETTINGS_KEY];
		if (empty($rule))
		{
			// an empty rule is no rule at all
			return;
		}

		self::validateConstantValueRule($constantType, $rule, $blockPosition, $itemPosition, $errors);
	}

	/**
	 * The contract of a value rule for a caller that holds the rule alone, outside any tree of blocks: the
	 * positions of the block and of the item mean nothing there, so only the verdict is returned.
	 */
	public static function isValidConstantValueRule(string $constantType, mixed $rule): bool
	{
		if (!self::supportsConstantValueRule($constantType))
		{
			return false;
		}

		$errors = [];
		self::validateConstantValueRule($constantType, $rule, 0, 0, $errors);

		return $errors === [];
	}

	private static function validateConstantValueRule(
		string $constantType,
		mixed $rule,
		int $blockPosition,
		int $itemPosition,
		array &$errors,
	): void
	{
		if (!is_array($rule))
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_IS_ARRAY',
					[
						'#name#' => Loc::getMessage('BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_VALUE_RULE'),
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);

			return;
		}

		$boundLabels = $constantType === FieldType::INT
			? [
				ValueRule::MIN => 'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_VALUE_RULE_MIN',
				ValueRule::MAX => 'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_VALUE_RULE_MAX',
			]
			: [
				ValueRule::MAX_LENGTH => 'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_CONSTANT_EDIT_VALUE_RULE_MAX_LENGTH',
			]
		;

		$hasInvalidBound = false;
		foreach ($rule as $key => $value)
		{
			$key = (string)$key;
			if (!isset($boundLabels[$key]))
			{
				$errors[] = self::makeValidationError(
					Loc::getMessage(
						'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_VALUE_RULE_KEY_UNKNOWN',
						[
							'#name#' => self::keyForMessage($key),
							'#itemPosition#' => $itemPosition,
							'#blockPosition#' => $blockPosition,
						]
					),
				);

				continue;
			}

			if (!self::isIntegerValueRuleBound($value))
			{
				$hasInvalidBound = true;
				$errors[] = self::makeValidationError(
					Loc::getMessage(
						'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_IS_INT',
						[
							'#name#' => Loc::getMessage($boundLabels[$key]),
							'#itemPosition#' => $itemPosition,
							'#blockPosition#' => $blockPosition,
						]
					),
				);
			}
		}

		if ($hasInvalidBound)
		{
			return;
		}

		$bounds = array_intersect_key($rule, $boundLabels);

		if (
			isset($bounds[ValueRule::MIN], $bounds[ValueRule::MAX])
			&& (int)$bounds[ValueRule::MIN] > (int)$bounds[ValueRule::MAX]
		)
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_VALUE_RULE_RANGE',
					[
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);
		}

		if (isset($bounds[ValueRule::MAX_LENGTH]) && (int)$bounds[ValueRule::MAX_LENGTH] <= 0)
		{
			$errors[] = self::makeValidationError(
				Loc::getMessage(
					'BIZPROC_SETUP_TEMPLATE_ACTIVITY_VALIDATOR_VALUE_RULE_MAX_LENGTH_POSITIVE',
					[
						'#itemPosition#' => $itemPosition,
						'#blockPosition#' => $blockPosition,
					]
				),
			);
		}
	}

	/**
	 * A key of the settings arrives from the client and the message about it travels back to the browser, so
	 * only a short safe subset of the key is reflected.
	 */
	private static function keyForMessage(string $key): string
	{
		return mb_substr((string)preg_replace('/[^A-Za-z0-9_.-]/', '', $key), 0, 32);
	}

	/**
	 * A bound reaches the server as a number from the client and as its decimal string after a round trip
	 * through the description of a template, so both forms are a bound. The pattern ends with /D so that a
	 * trailing newline is not a bound, and the digits are capped: a longer bound saturates on reading and
	 * stops limiting anything at all.
	 */
	private static function isIntegerValueRuleBound(mixed $value): bool
	{
		if (!is_int($value) && !is_string($value))
		{
			return false;
		}

		return preg_match('/^-?\d{1,' . self::VALUE_RULE_BOUND_MAX_DIGITS . '}$/D', (string)$value) === 1;
	}

	/**
	 * Value the form is filled with: the value stored in the template constant wins, the default of
	 * the setup wizard is the fallback for a constant that was never filled.
	 *
	 * A single bool reaches the form canonicalized: the storage may hold any synonym the server
	 * recognises, so both the positive and the negative ones become Y or N and keep their meaning
	 * against the wizard default. Only a value outside the known synonyms means nothing to a bool
	 * and counts as never filled. A native bool is canonicalized whatever the multiplicity — for a
	 * multiple value element by element — so a stored native value keeps its meaning instead of
	 * being read as an empty one. Otherwise multiplicity decides which path is taken, not the
	 * runtime form of the stored value: the synonyms of a multiple bool stay as they are.
	 */
	private static function resolveConstantDefault(
		string $constantType,
		bool $multiple,
		mixed $storedValue,
		string|array $wizardDefault,
	): string|array
	{
		if ($constantType === FieldType::BOOL && is_bool($storedValue))
		{
			$storedValue = self::canonicalizeNativeBool($storedValue);
		}
		elseif ($constantType === FieldType::BOOL && is_array($storedValue))
		{
			$storedValue = array_map(
				static fn(mixed $value): mixed => self::canonicalizeNativeBool($value),
				$storedValue,
			);
		}
		elseif (is_scalar($storedValue))
		{
			$storedValue = (string)$storedValue;
		}

		// the same definition of emptiness the apply path uses, so a legitimate zero is a value here too
		if (CBPHelper::isEmptyValue($storedValue) || (!is_string($storedValue) && !is_array($storedValue)))
		{
			return $wizardDefault;
		}

		if ($constantType === FieldType::BOOL && !$multiple)
		{
			if (!is_string($storedValue))
			{
				return $wizardDefault;
			}

			// the synonyms of BoolType::extractValue; anything outside both sets is never filled
			$synonym = mb_strtolower($storedValue);
			if (in_array($synonym, ['y', 'yes', 'true', '1'], true))
			{
				return 'Y';
			}

			if (in_array($synonym, ['n', 'no', 'false', '0'], true))
			{
				return 'N';
			}

			return $wizardDefault;
		}

		return $storedValue;
	}

	/**
	 * A native bool of a template built outside the wizard, cast the way BoolType::extractValue does:
	 * a plain string cast would turn false into the empty string and lose the stored "no", and the
	 * switcher of the form reads a value that is not a string as an empty one. Anything but a native
	 * bool is left to the caller.
	 */
	private static function canonicalizeNativeBool(mixed $value): mixed
	{
		if (!is_bool($value))
		{
			return $value;
		}

		return $value ? 'Y' : 'N';
	}

	/**
	 * Keeps a multiple constant default as an array (each element cast to string) and a single
	 * default as a string. Casting the whole array to string would store the literal "Array".
	 */
	private static function normalizeConstantDefault(mixed $default): string|array
	{
		if (is_array($default))
		{
			return array_values(array_map(static fn($value): string => (string)$value, $default));
		}

		return (string)($default ?? '');
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
				blockPosition: $blockPosition,
				itemPosition: $itemPosition,
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
			'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_NAME_ITEM',
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
			blockPosition: $blockPosition,
			itemPosition: $itemPosition,
		);

		return null;
	}

	/**
	 * The message is localized and written for the wizard owner, so the place an error was found at
	 * is also kept as data: parseSetupBlocks() reports positions to callers that must not read texts.
	 */
	private static function makeValidationError(
		?string $message,
		string $code = self::ERROR_CODE_NOT_EXIST,
		?int $blockPosition = null,
		?int $itemPosition = null,
	): array
	{
		$error = [
			self::ERROR_MESSAGE => $message,
			self::ERROR_CODE => $code,
		];

		if ($blockPosition !== null)
		{
			$error[self::ERROR_BLOCK_POSITION] = $blockPosition;
		}

		if ($itemPosition !== null)
		{
			$error[self::ERROR_ITEM_POSITION] = $itemPosition;
		}

		return $error;
	}

	/**
	 * Fills in default values for setup-block constants that were not passed by the caller.
	 *
	 * Needed for scenario/auto-start flows (e.g. DayPlannerStarter) that submit setup without
	 * constant values: required constants with a configured default must pass validation instead
	 * of failing the "required & empty" check before defaults are applied in setConstants().
	 * Scoped to block constants only, so template-only constants are not added to the input and
	 * do not trigger the "unknown id" diff in validateConstants().
	 */
	private function appendBlockConstantsDefaults(array $constantValues, BlockCollection $blocks): array
	{
		foreach ($blocks as $block)
		{
			foreach ($block->items as $item)
			{
				if (!$item instanceof Constant)
				{
					continue;
				}

				if (!array_key_exists($item->id, $constantValues))
				{
					$constantValues[$item->id] = self::autoFilledConstantValue($item);
				}
			}
		}

		return $constantValues;
	}

	/**
	 * An empty default of a required bool would fail the "required & empty" check, while the form itself
	 * submits N for the same state (each row of a multiple bool publishes its own N), so N is what the
	 * auto-fill path takes. An empty value of an optional bool is a legitimate "not set" and is left
	 * alone, as is any constant outside the blocks of the wizard.
	 */
	private static function autoFilledConstantValue(Constant $item): mixed
	{
		if (
			$item->constantType === FieldType::BOOL
			&& $item->required
			&& CBPHelper::isEmptyValue($item->default)
		)
		{
			return $item->multiple ? ['N'] : 'N';
		}

		return self::normalizeConstantValueForApply($item->constantType, $item->multiple, $item->default);
	}

	/**
	 * Multiple user constant default is stored as an array of tokens (["user_4", "group_hr1", ...]),
	 * but the apply path (GetFieldInputValue -> User::extractValueMultiple -> CBPHelper::usersStringToArray)
	 * expects a delimited string; a raw sequential array without Index is collapsed to its first element.
	 * Join the tokens with ";" so every selected user/department survives. Only multiple user defaults are
	 * touched; other types and single values are returned unchanged.
	 */
	private static function normalizeConstantValueForApply(string $constantType, bool $multiple, mixed $value): mixed
	{
		if ($constantType === FieldType::USER && $multiple && is_array($value))
		{
			return implode(';', array_map(static fn($token): string => (string)$token, $value));
		}

		return $value;
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
				$allConstantValues[$constantId] = self::normalizeConstantValueForApply(
					(string)($constant['Type'] ?? ''),
					\CBPHelper::getBool($constant['Multiple'] ?? false),
					$constant['Default'] ?? null,
				);
			}
		}

		return $allConstantValues;
	}

	private function validateConstants(
		array $constants,
		int $userId,
		BlockCollection $blocks,
		bool $skipAccessValidation = false,
	): ErrorCollection
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

				// the same definition of emptiness the prefill and apply paths use, so a zero is a value here too
				if ($item->required === true && CBPHelper::isEmptyValue($constantValue))
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

				if (!$skipAccessValidation)
				{
					$accessValidationResult = (new AccessValidationService())
						->isUserHasAccessToValue($fieldType->getTypeClass(), $userId, $value)
					;
					$errors = array_merge($errors, $accessValidationResult->getErrorCollection()->getValues());
				}
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
	 * Type codes a setup wizard constant may use for the given document type.
	 *
	 * Narrow public reading of the same list the settings panel is built from: only the codes travel
	 * out, never the localized titles or the entity-selector registry. `entityselector` is part of
	 * the list exactly when the panel offers it, i.e. when the registry holds at least one selector.
	 *
	 * @param array $documentType Complex document type the constants belong to.
	 * @return list<string>
	 */
	public static function getAllowedConstantTypes(array $documentType): array
	{
		$types = [];
		foreach (self::getConstantConfigurationList($documentType) as $configuration)
		{
			$types[] = (string)$configuration->toArray()['type'];
		}

		return $types;
	}

	/**
	 * @param array|null $documentType
	 * @return array<ConstantConfiguration>
	 */
	private static function getConstantConfigurationList(?array $documentType): array
	{
		$documentService = CBPRuntime::getRuntime()->getDocumentService();
		$types = $documentService->GetDocumentFieldTypes($documentType);

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
			FieldType::BOOL,
			FieldType::DATE,
			FieldType::DATETIME,
			BIDashboardType::getType(),
		];

		$constantConfigurationList = [];
		foreach ($types as $type => $options)
		{
			if (empty($options['Name'] ?? null) || !in_array($type, $allowedTypes, true))
			{
				continue;
			}

			$constantConfigurationList[] = new ConstantConfiguration(
				title: $options['Name'],
				type: $type,
				options: [],
			);
		}

		$entitySelectorType = CBPHelper::getEntitySelectorTypeInfo();
		if (!empty($entitySelectorType['Name']))
		{
			$selectorProvider = ServiceLocator::getInstance()->get(SelectorProvider::class);

			$selectors = $selectorProvider->getAll();
			if (!empty($selectors))
			{
				$constantConfigurationList[] = new ConstantConfiguration(
					title: $entitySelectorType['Name'],
					type: FieldType::ENTITYSELECTOR,
					options: [
						'selectors' => $selectors,
					],
				);
			}
		}

		return $constantConfigurationList;
	}

	private function appendConstantInfoToBlocks(BlockCollection $blocks, int $userId): BlockCollection
	{
		$patchedCollection = new BlockCollection();
		$projectFieldStateResolver = new ProjectFieldStateResolver();
		$projectFieldStateResolver->preloadDirect(
			$this->collectProjectConstantValues($blocks),
			$userId,
		);

		foreach ($blocks as $block)
		{
			$patchedCollection->add(new Block(
				$this->appendConstantInfoToItemCollection(
					$block->items,
					$userId,
					$projectFieldStateResolver,
				),
			));
		}

		return $patchedCollection;
	}

	/**
	 * @return list<mixed>
	 */
	private function collectProjectConstantValues(BlockCollection $blocks): array
	{
		$projectConstantValues = [];
		foreach ($blocks as $block)
		{
			foreach ($block->items as $item)
			{
				if (!$item instanceof Constant || $item->constantType !== ProjectType::getType())
				{
					continue;
				}

				$projectConstantValues[] = self::resolveConstantDefault(
					$item->constantType,
					$item->multiple,
					$this->getConstant($item->id),
					$item->default,
				);
			}
		}

		return $projectConstantValues;
	}

	private function appendConstantInfoToItemCollection(
		ItemCollection $items,
		int $userId,
		ProjectFieldStateResolver $projectFieldStateResolver,
	): ItemCollection
	{
		$patchedItems = new ItemCollection();
		foreach ($items as $item)
		{
			$patchedItems->add($this->appendConstantInfoToItem(
				$item,
				$userId,
				$projectFieldStateResolver,
			));
		}

		return $patchedItems;
	}

	private function appendConstantInfoToItem(
		Item $item,
		int $userId,
		ProjectFieldStateResolver $projectFieldStateResolver,
	): Item
	{
		if (!$item instanceof Constant)
		{
			return $item;
		}

		$defaultValue = self::resolveConstantDefault(
			$item->constantType,
			$item->multiple,
			$this->getConstant($item->id),
			$item->default,
		);

		$settings = $item->settings;
		if (!is_array($settings))
		{
			$settings = [];
		}

		if ($item->constantType === FieldType::ENTITYSELECTOR)
		{
			$selectorProvider = ServiceLocator::getInstance()->get(SelectorProvider::class);
			$selectorId = $item->settings[self::CONSTANT_SETTINGS_ENTITYSELECTOR_SELECTOR_ID] ?? null;
			$selectorConfiguration = $selectorProvider->getById($selectorId);

			$settings[self::CONSTANT_SETTINGS_ENTITYSELECTOR_SELECTOR] = $selectorConfiguration?->toArray() ?? [];
		}

		$meta = $this->getProjectConstantMeta(
			$item,
			$defaultValue,
			$userId,
			$projectFieldStateResolver,
		);

		return new Constant(
			id: $item->id,
			name: $item->name,
			constantType: $item->constantType,
			description: $item->description,
			multiple: $item->multiple,
			required: $item->required,
			options: $item->options,
			settings: $settings,
			default: $defaultValue,
			meta: $meta,
		);
	}

	/**
	 * The description editor gives the author emphasis, lists and links, and no way to put a picture,
	 * a video, a spoiler or a code block into the text. Such markup still reaches the column through
	 * import, the REST API and values stored before the editor existed, and the form renders what it
	 * is given, so the value is brought back to the markup the editor produces on the only path the
	 * description takes to the form.
	 *
	 * Dropping the forbidden tags keeps the bounds of the block ones, so the text around a spoiler
	 * or a quote does not become one word. A media block written as strict markup goes next, with
	 * its url: the closing tag says where the url ends. That pass runs before the escaping, because
	 * after it the brackets of the block are entities and the block has no strict form left to match.
	 *
	 * Every bracket left that is not strict markup turns into an entity, which the parser of the
	 * form reads back as a bracket — user text like `[руб]` reaches the reader as it was written.
	 * A media tag with no closing one, or in a form that is not strict markup, survives that as
	 * allowed markup and is taken out after it alone, leaving its url as plain text: by the grammar
	 * of the form the content of such a tag is the whole rest of the description, so taking it would
	 * cost the author the text written after the tag.
	 *
	 * The allowed markup is brought to the one spelling the tokenizer of the form reads as a pair
	 * last: it looks for the closing tag of an opening one by the name as written, so `[B]…[/b]` and
	 * `[b]…[/b ]` — forms the column takes through import and the REST API — would reach the reader
	 * as the tags themselves instead of the text they format.
	 *
	 * What is left is markup the form can render. Whether it can render it at a price the reader can
	 * afford is a separate question, and isTooHeavyToRenderAsMarkup() answers it last, once the value
	 * is exactly the one the form will be given. A description past the bounds goes down to plain
	 * text, and every bracket of that text is escaped again: the form parses whatever it is given,
	 * and text taken out of markup carries brackets the parser would read as markup of its own.
	 */
	private static function withoutUnsupportedMarkup(?string $description): ?string
	{
		if ($description === null)
		{
			return $description;
		}

		$description = DescriptionMarkup::stripForbiddenBbTagsKeepingBlockBounds($description);
		$description = DescriptionMarkup::stripMediaBlocks($description);
		$description = DescriptionMarkup::stripMediaBbTags(
			DescriptionMarkup::escapeDanglingBrackets($description)
		);
		$description = DescriptionMarkup::canonicalizeAllowedBbTags($description);

		return self::isTooHeavyToRenderAsMarkup($description)
			? DescriptionMarkup::escapeBrackets(DescriptionMarkup::toPlainText($description))
			: $description
		;
	}

	/**
	 * The form renders the description with the BBCode formatter of `ui`, and both halves of that
	 * formatter walk the value without a bound: the tokenizer looks for the closing tag of every
	 * paired one by scanning the whole rest of the text, and the formatter calls itself once per
	 * level of nesting. Neither is a problem for what an author writes, and the column is a MySQL
	 * `text` that takes tens of thousands of characters through import and the REST API, so a value
	 * built to be heavy costs the reader of the wizard seconds of a frozen tab or — past a few
	 * thousand levels — a stack overflow that leaves the description blank.
	 *
	 * Measured in Chrome on the real extension: 500 nested tags 70 ms, 1 000 227 ms, 2 000 889 ms,
	 * and between 2 200 and 2 500 the stack breaks. Opening tags left unclosed cost the same, because
	 * the parser keeps a level open for each of them to the end of the text: 3 000 of them break the
	 * stack inside 9 000 characters. What an author writes stays far below all of it — a list of 500
	 * items is 13 ms, 65 000 characters of plain text 18 ms — so the bounds below sit above anything
	 * a person types and far under the point where the formatter is in trouble.
	 *
	 * Past any of them the description reaches the form as plain text, which is what the reader saw
	 * before the form learned to render markup, rather than as thousands of raw tags on the screen.
	 */
	private static function isTooHeavyToRenderAsMarkup(string $description): bool
	{
		if (self::getLengthTheFormReads($description) > self::DESCRIPTION_MAX_LENGTH)
		{
			return true;
		}

		preg_match_all(self::DESCRIPTION_TAG_PATTERN, $description, $tags, PREG_SET_ORDER);

		if (count($tags) > self::DESCRIPTION_MAX_TAG_COUNT)
		{
			return true;
		}

		return self::getMarkupNestingDepth($tags) > self::DESCRIPTION_MAX_NESTING_DEPTH;
	}

	/**
	 * An escaped bracket is spelled with five characters and reaches the reader as the one the author
	 * wrote, so it is worth one here as well. Counting it as five made the length say how the value is
	 * spelled rather than how much of it the form has to render, and a description the editor itself
	 * accepts — its own bound is 16 000 characters — lost its markup over a few hundred brackets of
	 * plain text.
	 */
	private static function getLengthTheFormReads(string $description): int
	{
		return mb_strlen(str_replace(['&#91;', '&#93;'], ['[', ']'], $description));
	}

	/**
	 * Counts the tags open at once the way the stack machine of the form does — the `normalizeTokens`
	 * of ui/install/js/ui/bbcode/parser/src/parser.js — because only a count that follows the same
	 * rules says how deep the form will actually go.
	 *
	 * A closing tag looks down the stack for the nearest open tag of the same name and takes it out
	 * with everything opened inside it; one that matches nothing is text to the form and leaves the
	 * stack alone. Taking a level off every closing tag instead reads `[b][/i]` repeated as one level
	 * while the form builds one level per pair. Names are compared in lower case, the way the
	 * tokenizer of the form stores them, so `[B]…[/b]` closes.
	 *
	 * A tag never closed keeps its level to the end of the value: that is what makes a run of opening
	 * tags cost as much as nesting the same tags into each other.
	 */
	private static function getMarkupNestingDepth(array $tags): int
	{
		$openTags = [];
		$openCounts = [];
		$maxDepth = 0;

		foreach ($tags as $tag)
		{
			$name = mb_strtolower($tag['name']);

			if ($tag['closing'] !== '')
			{
				self::closeMarkupTag($openTags, $openCounts, $name);

				continue;
			}

			if ($name === self::DESCRIPTION_LIST_ITEM_TAG)
			{
				if (($openCounts[self::DESCRIPTION_LIST_TAG] ?? 0) === 0)
				{
					continue;
				}

				self::closeListItemOfTheSameList($openTags, $openCounts);
			}

			$openTags[] = $name;
			$openCounts[$name] = ($openCounts[$name] ?? 0) + 1;
			$maxDepth = max($maxDepth, count($openTags));
		}

		return $maxDepth;
	}

	/**
	 * Takes the nearest open tag of the name out together with everything opened inside it. A name
	 * that is not open at all is left alone: such a closing tag is text to the form, and taking a
	 * level off for it is what let a value of mismatched pairs pass as flat.
	 */
	private static function closeMarkupTag(array &$openTags, array &$openCounts, string $name): void
	{
		if (($openCounts[$name] ?? 0) === 0)
		{
			return;
		}

		for ($position = count($openTags) - 1; $position >= 0; --$position)
		{
			if ($openTags[$position] === $name)
			{
				self::dropOpenTagsFrom($openTags, $openCounts, $position);

				return;
			}
		}
	}

	/**
	 * A `[*]` closes the item standing before it, but only an item of the same list: an item of the
	 * list this one is nested in stays open, so nested lists keep their levels. Reaching the list
	 * itself first means there is no item of it open yet.
	 */
	private static function closeListItemOfTheSameList(array &$openTags, array &$openCounts): void
	{
		for ($position = count($openTags) - 1; $position >= 0; --$position)
		{
			if ($openTags[$position] === self::DESCRIPTION_LIST_TAG)
			{
				return;
			}

			if ($openTags[$position] === self::DESCRIPTION_LIST_ITEM_TAG)
			{
				self::dropOpenTagsFrom($openTags, $openCounts, $position);

				return;
			}
		}
	}

	private static function dropOpenTagsFrom(array &$openTags, array &$openCounts, int $position): void
	{
		foreach (array_splice($openTags, $position) as $closedTag)
		{
			--$openCounts[$closedTag];
		}
	}

	private function getProjectConstantMeta(
		Constant $item,
		string|array $defaultValue,
		int $userId,
		ProjectFieldStateResolver $projectFieldStateResolver,
	): array
	{
		if ($item->constantType !== ProjectType::getType())
		{
			return [];
		}

		$projectState = $projectFieldStateResolver->resolveDirect(
			$defaultValue,
			$userId,
		);
		if ($projectState === null)
		{
			return [];
		}

		return ['projectState' => $projectState];
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
			'BIZPROC_SETUP_TEMPLATE_ACTIVITY_LABEL_NAME_ITEM',
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
				blockPosition: $blockPosition,
				itemPosition: $itemPosition,
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
		$skipAccessValidation = (bool)($eventParameters[4] ?? false);
		$applyDefaults = (bool)($eventParameters[5] ?? false);
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

		if ($applyDefaults)
		{
			$constantValues = $this->appendBlockConstantsDefaults($constantValues, $blocks);
		}

		$errors = $this->validateConstants($constantValues, $userId, $blocks, $skipAccessValidation);
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
