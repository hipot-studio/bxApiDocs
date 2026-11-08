<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Bizproc\Result\DraftCreationResult;
use Bitrix\Sign\Type\Bizproc\DraftActivityMessageCode;
use Bitrix\Sign\Type\Bizproc\DraftFileSource;
use Bitrix\Sign\Type\BlankFileExtension;

/**
 * @property string $fileSource
 * @property array $file
 * @property string $fileExpression
 * @property string[] $responsible
 * @property string $creationStatus
 * @property int $documentId
 * @property string $documentStatus
 * @property int $smartDocumentId
 */
class CBPSignB2EDocumentDraftActivity extends BaseActivity implements IBPConfigurableActivity
{
	public const PARAM_FILE_SOURCE = 'fileSource';
	public const PARAM_FILE = 'file';
	public const PARAM_FILE_EXPRESSION = 'fileExpression';
	public const PARAM_RESPONSIBLE = 'responsible';

	public const FIELD_FILE_SOURCE = 'file_source';
	public const FIELD_FILE = 'file';
	public const FIELD_FILE_EXPRESSION = 'file_expression';
	public const FIELD_RESPONSIBLE = 'responsible';

	public const RETURN_PARAM_CREATION_STATUS = 'creationStatus';
	public const RETURN_PARAM_DOCUMENT_ID = 'documentId';
	public const RETURN_PARAM_DOCUMENT_STATUS = 'documentStatus';
	public const RETURN_PARAM_SMART_DOCUMENT_ID = 'smartDocumentId';

	public const STATUS_SUCCESS = 'SUCCESS';
	public const STATUS_FAILED = 'FAILED';

	/**
	 * The phrase of an unavailable chain, taken by its own key and not through the code table of the
	 * module: the module that is missing can be sign itself, and then that table cannot be loaded.
	 * @see \Bitrix\Sign\Type\Bizproc\DraftActivityMessageCode::UNAVAILABLE the same phrase by code
	 */
	private const MESSAGE_ID_UNAVAILABLE = 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_UNAVAILABLE';

	protected static $requiredModules = ['sign', 'crm'];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->setPropertiesTypes([
			self::PARAM_FILE_SOURCE => [
				'Type' => FieldType::SELECT,
			],
			self::PARAM_FILE => [
				'Type' => FieldType::FILE,
				'Multiple' => true,
			],
			self::PARAM_FILE_EXPRESSION => [
				'Type' => FieldType::STRING,
			],
			self::PARAM_RESPONSIBLE => [
				'Type' => FieldType::USER,
			],
			//return
			self::RETURN_PARAM_CREATION_STATUS => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_DOCUMENT_ID => [
				'Type' => FieldType::INT,
			],
			self::RETURN_PARAM_DOCUMENT_STATUS => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_SMART_DOCUMENT_ID => [
				'Type' => FieldType::INT,
			],
		]);

		$this->arProperties = [
			'Title' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ROBOT_TITLE'),
			self::PARAM_FILE_SOURCE => null,
			self::PARAM_FILE => null,
			self::PARAM_FILE_EXPRESSION => null,
			self::PARAM_RESPONSIBLE => null,
			//return
			self::RETURN_PARAM_CREATION_STATUS => null,
			self::RETURN_PARAM_DOCUMENT_ID => null,
			self::RETURN_PARAM_DOCUMENT_STATUS => null,
			self::RETURN_PARAM_SMART_DOCUMENT_ID => null,
		];
	}

	protected function reInitialize(): void
	{
		parent::reInitialize();

		$this->{self::RETURN_PARAM_CREATION_STATUS} = null;
		$this->{self::RETURN_PARAM_DOCUMENT_ID} = null;
		$this->{self::RETURN_PARAM_DOCUMENT_STATUS} = null;
		$this->{self::RETURN_PARAM_SMART_DOCUMENT_ID} = null;
	}

	/**
	 * Only gates the activity catalog for the current user: the platform never calls it on execution.
	 * The chain flag already covers B2E availability and the regional robot publicity.
	 */
	public static function isAvailable(): bool
	{
		return static::checkModules() && Feature::instance()->isB2eBizprocChainEnabled();
	}

	protected function isExecutionAvailable(): bool
	{
		return
			static::checkModules()
			&& Feature::instance()->isB2eBizprocChainEnabled(
				userId: (int)$this->getTemplateUserId(),
			)
		;
	}

	/**
	 * The base class neither checks activity availability on execution nor tells why required modules
	 * are missing. A saved activity must therefore be stopped here before the properties are read. The
	 * next link of the chain reads the returns whatever the outcome, so the refusal is told here too.
	 *
	 * @see \Bitrix\Bizproc\Activity\BaseActivity::execute() the same guard, silent
	 */
	public function execute()
	{
		if (!$this->isExecutionAvailable())
		{
			$this->resetReturns();
			$this->logError((string)Loc::getMessage(self::MESSAGE_ID_UNAVAILABLE));

			return \CBPActivityExecutionStatus::Closed;
		}

		return parent::execute();
	}

	/**
	 * The whole run is one call of the flow of the module: the context is read, the flow is asked and
	 * its outcome is laid out into the returns and the log. Every decision about the draft itself
	 * belongs to the flow, so no branch here looks at the data it carries.
	 */
	protected function internalExecute(): ErrorCollection
	{
		$this->resetReturns();

		[$sourceEntityTypeId, $sourceEntityId] = $this->resolveSourceEntity();

		$result = Container::instance()->getDocumentDraftCreator()->create(
			fileSource: $this->readFileSourceCode(),
			filePairValue: $this->resolveFileValue(self::PARAM_FILE),
			fileExpressionValue: $this->resolveFileValue(self::PARAM_FILE_EXPRESSION),
			responsibleUserId: $this->resolveResponsibleId(),
			workflowTemplateId: (int)$this->getWorkflowTemplateId(),
			activityName: $this->getName(),
			workflowInstanceId: (string)$this->getWorkflowInstanceId(),
			sourceEntityTypeId: $sourceEntityTypeId,
			sourceEntityId: $sourceEntityId,
		);

		if (!$result->isSuccess())
		{
			return self::collectFailure($result);
		}

		$this->fillReturns($result);

		if ($result->isReused)
		{
			$this->log(self::describeCode(DraftActivityMessageCode::DOCUMENT_REUSED));
		}

		foreach ($result->getWarnings() as $warning)
		{
			$this->log(self::describeError($warning));
		}

		return new ErrorCollection();
	}

	/**
	 * The next link of the chain reads the returns whatever the outcome, so no exit may leave one at
	 * the value of the previous run. setProperty() is the only way to reset them: a plain assignment
	 * reaches arProperties alone, while a read answers from the prepared values first.
	 *
	 * @see \Bitrix\Bizproc\Activity\BaseActivity::__get()
	 */
	private function resetReturns(): void
	{
		$this->setProperty(self::RETURN_PARAM_CREATION_STATUS, self::STATUS_FAILED);
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_ID, 0);
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_STATUS, '');
		$this->setProperty(self::RETURN_PARAM_SMART_DOCUMENT_ID, 0);
	}

	/**
	 * A document created now and a document taken by the idempotency key are described the same way,
	 * so both fill the returns with one and the same code.
	 */
	private function fillReturns(DraftCreationResult $result): void
	{
		$this->setProperty(self::RETURN_PARAM_CREATION_STATUS, self::STATUS_SUCCESS);
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_ID, (int)$result->documentId);
		$this->setProperty(self::RETURN_PARAM_DOCUMENT_STATUS, (string)$result->documentStatus);
		$this->setProperty(self::RETURN_PARAM_SMART_DOCUMENT_ID, (int)$result->smartDocumentId);
	}

	private function readFileSourceCode(): ?string
	{
		$fileSource = $this->{self::PARAM_FILE_SOURCE};

		return is_scalar($fileSource) && (string)$fileSource !== '' ? (string)$fileSource : null;
	}

	/**
	 * Both source values are read as the template carries them and resolved by the platform as a
	 * file: the prepared properties are converted by the declared type, and a file value does not
	 * survive that. The type of the resolve belongs to the call and not to the declaration, so the
	 * standalone expression is resolved as a file too.
	 *
	 * @see \CBPMailActivity::getFiles() the same read
	 */
	private function resolveFileValue(string $property): mixed
	{
		return $this->parseValue($this->getRawProperty($property), FieldType::FILE);
	}

	/**
	 * Zero is a legitimate answer of the selection: the flow reports an unresolved responsible by a
	 * code of its own.
	 */
	private function resolveResponsibleId(): int
	{
		$responsible = $this->{self::PARAM_RESPONSIBLE};

		return \CBPHelper::isEmptyValue($responsible)
			? 0
			: (int)\CBPHelper::extractFirstUser($responsible, $this->getDocumentId())
		;
	}

	/**
	 * The entity the process runs on. The public helper of crm is asked for it: it splits the
	 * identifier of a dynamic type by its last underscore, and a parser of our own would be a second
	 * place to fix. A pair that stays incomplete is no reference at all, and the flow degrades to
	 * writing the origin without one.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function resolveSourceEntity(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [0, 0];
		}

		$documentId = $this->getDocumentId();
		if (!isset($documentId[2]))
		{
			return [0, 0];
		}

		[$entityTypeId, $entityId] = \CCrmBizProcHelper::resolveEntityId($documentId);

		return [(int)$entityTypeId, (int)$entityId];
	}

	/**
	 * Turns the codes of the flow into the phrases of the log. The reason of the refusal always comes
	 * first; the compensation adds a second error, and the one telling that the card was left behind
	 * carries that reason inside its own phrase instead of standing next to it.
	 */
	private static function collectFailure(DraftCreationResult $result): ErrorCollection
	{
		$errors = $result->getErrors();
		$messages = [self::describeError(array_shift($errors))];

		foreach ($errors as $error)
		{
			$code = DraftActivityMessageCode::tryFrom((string)$error->getCode());
			if ($code === DraftActivityMessageCode::COMPENSATION_DONE)
			{
				$messages[0] = (string)Loc::getMessage($code->messageId(), [
					DraftActivityMessageCode::REASON_PLACEHOLDER => $messages[0],
					DraftActivityMessageCode::DOCUMENT_ID_PLACEHOLDER => (int)$error->getCustomData(),
				]);

				continue;
			}

			$messages[] = self::describeError($error);
		}

		$collection = new ErrorCollection();
		foreach ($messages as $message)
		{
			$collection->setError(new Error($message));
		}

		return $collection;
	}

	/**
	 * The phrase of the module for the code of the error. A refusal of a foreign service carries its
	 * own text, and that text is escaped right here, where it enters the phrase: the log of the
	 * process is rendered as raw HTML. Nothing else on the way is escaped, or a text that never
	 * reaches HTML would be escaped twice.
	 *
	 * @param Error|null $error null stands for a refusal that came without an error of its own: the
	 *   reserve phrase then tells the author of the process that much and no more
	 */
	private static function describeError(?Error $error): string
	{
		$code = DraftActivityMessageCode::tryFrom((string)$error?->getCode());
		$reason = trim((string)$error?->getMessage());

		if ($code === null || $code === DraftActivityMessageCode::UNKNOWN_REASON)
		{
			return DraftActivityMessageCode::getUnknownReasonMessage($reason);
		}

		// the phrase asks the author of the process to fill the card in by hand, so it names that card
		if ($code === DraftActivityMessageCode::RESPONSIBLE_ASSIGN_FAILED)
		{
			return (string)Loc::getMessage($code->messageId(), [
				DraftActivityMessageCode::DOCUMENT_ID_PLACEHOLDER => (int)$error?->getCustomData(),
			]);
		}

		$message = self::describeCode($code);

		return $reason === '' || !self::carriesServiceText($code)
			? $message
			: $message . ' ' . htmlspecialcharsbx($reason)
		;
	}

	private static function describeCode(DraftActivityMessageCode $code): string
	{
		$replace = $code === DraftActivityMessageCode::UNSUPPORTED_EXTENSION
			? ['#EXTENSIONS#' => implode(', ', BlankFileExtension::ALLOWED)]
			: []
		;

		return (string)Loc::getMessage($code->messageId(), $replace);
	}

	/**
	 * Only these three codes append the text of a foreign service to their phrase. Every other
	 * refusal is told apart by its code alone: the checks of the tariff, for one, answer with an
	 * empty message, and appending it would give an empty tail instead of a reason.
	 */
	private static function carriesServiceText(DraftActivityMessageCode $code): bool
	{
		return match ($code)
		{
			DraftActivityMessageCode::BLANK_CREATE_FAILED,
			DraftActivityMessageCode::DOCUMENT_CREATE_FAILED,
			DraftActivityMessageCode::DOCUMENT_UPLOAD_FAILED => true,
			default => false,
		};
	}

	/**
	 * Both file sources live here: the pair of the canonical control (source type plus value) and the
	 * standalone expression. None of them is required, because exactly one of the two has to be filled
	 * and the base required check cannot express that rule.
	 */
	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return [
			self::PARAM_FILE_SOURCE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_SOURCE'),
				'FieldName' => self::FIELD_FILE_SOURCE,
				'Type' => FieldType::SELECT,
				'Options' => self::getFileSourceOptions(),
				'Required' => false,
			],
			self::PARAM_FILE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_FILE'),
				'FieldName' => self::FIELD_FILE,
				'Type' => FieldType::FILE,
				'Multiple' => true,
				'Required' => false,
			],
			self::PARAM_FILE_EXPRESSION => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_FILE_EXPRESSION'),
				'FieldName' => self::FIELD_FILE_EXPRESSION,
				'Type' => FieldType::STRING,
				'Required' => false,
			],
			self::PARAM_RESPONSIBLE => [
				'Name' => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_RESPONSIBLE'),
				'FieldName' => self::FIELD_RESPONSIBLE,
				'Type' => FieldType::USER,
				'Required' => true,
			],
		];
	}

	/**
	 * The new designer renders no canonical file control, so the pair would stay unfilled there:
	 * only the expression source and the responsible reach it.
	 */
	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return array_intersect_key(
			static::getPropertiesDialogMap(),
			array_flip([self::PARAM_FILE_EXPRESSION, self::PARAM_RESPONSIBLE]),
		);
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$result = parent::extractPropertiesValues($dialog, $fieldsMap);
		if (!$result->isSuccess() || !isset($fieldsMap[self::PARAM_FILE]))
		{
			return $result;
		}

		$properties = $result->getData();
		if (!self::isDiskSource($properties[self::PARAM_FILE_SOURCE] ?? null))
		{
			return $result;
		}

		// in the disk mode the control writes plain object ids, and the base file type keeps
		// only uploads and expressions, so the value has to be read from the form again
		$properties[self::PARAM_FILE] = self::extractDiskFileIds($dialog->getCurrentValues());
		$result->setData($properties);

		return $result;
	}

	public static function validateProperties($testProperties = [], ?CBPWorkflowTemplateUser $user = null): array
	{
		$errors = parent::validateProperties($testProperties, $user);
		if (!static::checkModules())
		{
			return $errors;
		}

		return array_merge(
			$errors,
			self::validateFileSource(is_array($testProperties) ? $testProperties : []),
			self::validateDocumentCreationRight($user),
		);
	}

	/**
	 * Exactly one file source has to be filled: either the pair of the source type and the value,
	 * or the standalone expression. The base required check cannot express such a rule, so none of
	 * the three properties is marked required.
	 */
	private static function validateFileSource(array $properties): array
	{
		$fileValues = self::normalizeFileValues($properties[self::PARAM_FILE] ?? null);
		$hasExpression = trim((string)($properties[self::PARAM_FILE_EXPRESSION] ?? '')) !== '';

		if ($fileValues === [] && !$hasExpression)
		{
			return [
				self::makeError(
					'NotExist',
					self::PARAM_FILE,
					'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_FILE_NOT_SET',
				),
			];
		}

		if ($fileValues !== [] && $hasExpression)
		{
			return [
				self::makeError(
					'FileSourceConflict',
					self::PARAM_FILE,
					'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_FILE_SOURCE_CONFLICT',
				),
			];
		}

		if (count($fileValues) > 1)
		{
			return [
				self::makeError(
					'TooManyFiles',
					self::PARAM_FILE,
					'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_FILE_SINGLE_EXPECTED',
				),
			];
		}

		if ($fileValues === [] || !self::isDiskSource($properties[self::PARAM_FILE_SOURCE] ?? null))
		{
			return [];
		}

		return self::validateDiskFile($fileValues[0]);
	}

	/**
	 * Only the disk value can be checked while the settings are saved: there the value is an id and
	 * the extension is known. Both other sources carry a platform expression resolved at runtime.
	 */
	private static function validateDiskFile(mixed $value): array
	{
		$fileId = self::toDiskFileId($value);
		if ($fileId < 1)
		{
			return [
				self::makeError(
					'InvalidDiskFile',
					self::PARAM_FILE,
					'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_DISK_FILE_INVALID',
				),
			];
		}

		if (!Loader::includeModule('disk'))
		{
			return [];
		}

		$diskFile = \Bitrix\Disk\File::loadById($fileId);
		// a missing object is left to the runtime: it resolves the file anyway and reports it there
		if ($diskFile === null || BlankFileExtension::isAllowed((string)$diskFile->getExtension()))
		{
			return [];
		}

		return [
			self::makeError(
				'NotAllowedExtension',
				self::PARAM_FILE,
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_FILE_EXTENSION',
				['#EXTENSIONS#' => implode(', ', BlankFileExtension::ALLOWED)],
			),
		];
	}

	/**
	 * The subject is the user the platform passes into the check, not the current one. A zero user
	 * reaches this point only from the service calls of the template loader, where the modifier is
	 * unknown, so the right is not checked there.
	 */
	private static function validateDocumentCreationRight(?CBPWorkflowTemplateUser $user): array
	{
		$userId = (int)($user?->getId() ?? 0);
		if ($userId < 1)
		{
			return [];
		}

		if ((new AccessController($userId))->check(ActionDictionary::ACTION_B2E_DOCUMENT_ADD))
		{
			return [];
		}

		return [
			self::makeError(
				'AccessDenied',
				'',
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_ACCESS_DENIED',
			),
		];
	}

	/**
	 * @return list<mixed>
	 */
	private static function normalizeFileValues(mixed $value): array
	{
		$values = is_array($value) ? $value : [$value];

		return array_values(array_filter(
			$values,
			static fn(mixed $item): bool => is_scalar($item) && trim((string)$item) !== '',
		));
	}

	private static function toDiskFileId(mixed $value): int
	{
		if (is_int($value))
		{
			return max($value, 0);
		}

		return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int)$value : 0;
	}

	private static function makeError(string $code, string $parameter, string $messageId, array $replace = []): array
	{
		return [
			'code' => $code,
			'parameter' => $parameter,
			'message' => Loc::getMessage($messageId, $replace),
		];
	}

	private static function isDiskSource(mixed $fileSource): bool
	{
		return Loader::includeModule('sign') && $fileSource === DraftFileSource::DISK->value;
	}

	/**
	 * @return list<int>
	 */
	private static function extractDiskFileIds(array $currentValues): array
	{
		$values = $currentValues[self::FIELD_FILE] ?? [];
		if (!is_array($values))
		{
			$values = [$values];
		}

		$fileIds = [];
		foreach ($values as $value)
		{
			if (is_numeric($value) && (int)$value > 0)
			{
				$fileIds[] = (int)$value;
			}
		}

		return $fileIds;
	}

	private static function getFileSourceOptions(): array
	{
		// the source codes belong to sign/lib and are not autoloadable without the module
		if (!Loader::includeModule('sign'))
		{
			return [];
		}

		$options = [
			DraftFileSource::FILE->value => Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_SOURCE_FILE'),
		];

		if (Loader::includeModule('disk'))
		{
			$options[DraftFileSource::DISK->value] =
				Loc::getMessage('SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_SOURCE_DISK')
			;
		}

		return $options;
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}
