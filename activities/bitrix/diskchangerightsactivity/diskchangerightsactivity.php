<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Command\Rights\ChangeObjectRightsCommand;
use Bitrix\Disk\Internal\Enum\ObjectRightsChangeMode;
use Bitrix\Disk\Internal\Enum\ObjectValueSource;
use Bitrix\Disk\Internal\Integration\Bizproc\AccessCodeMapper;
use Bitrix\Disk\Internal\Integration\Bizproc\ObjectResolver;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserAccessTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Grants or revokes an object right of Disk on a file or a folder.
 *
 * The activity itself only brings the properties of the template to the types of the domain: the object
 * comes from {@see ObjectResolver}, the recipients from {@see AccessCodeMapper}, and the change is made
 * by {@see ChangeObjectRightsCommand}. Everything the run decided - an applied code, a skipped one, a
 * right left standing because a parent folder carries it - is written to the journal of the process:
 * a change of rights nobody can read afterwards is worse than no change at all.
 */
class CBPDiskChangeRightsActivity extends BaseActivity implements IBPConfigurableActivity
{
	protected static $requiredModules = ['disk'];

	private const DEFAULT_TASK_NAME = 'disk_access_read';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'ObjectId' => null,
			'ObjectSource' => ObjectValueSource::ObjectId->value,
			'Mode' => ObjectRightsChangeMode::Grant->value,
			'TaskName' => self::DEFAULT_TASK_NAME,
			'Recipients' => null,
			'Operator' => null,
		];

		$this->setPropertiesTypes([
			'ObjectId' => ['Type' => FieldType::STRING],
			'ObjectSource' => ['Type' => FieldType::SELECT, 'Options' => static::getObjectSourceOptions()],
			'Mode' => ['Type' => FieldType::SELECT, 'Options' => static::getModeOptions()],
			'TaskName' => ['Type' => FieldType::SELECT, 'Options' => static::getTaskOptions()],
			'Recipients' => ['Type' => FieldType::USER, 'Multiple' => true],
			'Operator' => ['Type' => FieldType::USER],
		]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	/**
	 * The fields of the settings. The node designer reads them through the configurator, which asks this
	 * map and not the dialog one, so the map lives here and the dialog takes it over.
	 */
	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'ObjectId' => [
				'Name' => Loc::getMessage('BPDCHR_MAP_OBJECT_ID'),
				'Description' => Loc::getMessage('BPDCHR_MAP_OBJECT_ID_HINT'),
				'FieldName' => 'object_id',
				'Type' => FieldType::STRING,
				'Required' => true,
				'AllowSelection' => true,
			],
			'ObjectSource' => [
				'Name' => Loc::getMessage('BPDCHR_MAP_OBJECT_SOURCE'),
				'Description' => Loc::getMessage('BPDCHR_MAP_OBJECT_SOURCE_HINT'),
				'FieldName' => 'object_source',
				'Type' => FieldType::SELECT,
				'Options' => static::getObjectSourceOptions(),
				'Default' => ObjectValueSource::ObjectId->value,
				'Required' => true,
				// How the setting above is to be read is settled when the template is written, not on the run.
				'AllowSelection' => false,
			],
			'Mode' => [
				'Name' => Loc::getMessage('BPDCHR_MAP_MODE'),
				'FieldName' => 'mode',
				'Type' => FieldType::SELECT,
				'Options' => static::getModeOptions(),
				'Default' => ObjectRightsChangeMode::Grant->value,
				'Required' => true,
				'AllowSelection' => true,
			],
			'TaskName' => [
				'Name' => Loc::getMessage('BPDCHR_MAP_TASK_NAME'),
				'FieldName' => 'task_name',
				'Type' => FieldType::SELECT,
				'Options' => static::getTaskOptions(),
				'Default' => self::DEFAULT_TASK_NAME,
				'Required' => true,
				'AllowSelection' => true,
			],
			'Recipients' => [
				'Name' => Loc::getMessage('BPDCHR_MAP_RECIPIENTS'),
				'FieldName' => 'recipients',
				'Type' => FieldType::USER,
				'Multiple' => true,
				'Required' => true,
				'AllowSelection' => true,
			],
			'Operator' => [
				'Name' => Loc::getMessage('BPDCHR_MAP_OPERATOR'),
				'FieldName' => 'operator',
				'Type' => FieldType::USER,
				'Required' => true,
				'AllowSelection' => true,
			],
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	/**
	 * A number in a setting filled by hand is the identifier of an object of disk, and in the value of a
	 * file field it is the identifier of an attachment. Both are numbers of tables of their own and the
	 * ranges overlap, so the template says which of the two it hands over instead of leaving it to a guess.
	 */
	public static function getObjectSourceOptions(): array
	{
		return [
			ObjectValueSource::ObjectId->value => (string)Loc::getMessage('BPDCHR_OBJECT_SOURCE_OBJECT'),
			ObjectValueSource::FileField->value => (string)Loc::getMessage('BPDCHR_OBJECT_SOURCE_FILE_FIELD'),
		];
	}

	public static function getModeOptions(): array
	{
		return [
			ObjectRightsChangeMode::Grant->value => (string)Loc::getMessage('BPDCHR_MODE_GRANT'),
			ObjectRightsChangeMode::Revoke->value => (string)Loc::getMessage('BPDCHR_MODE_REVOKE'),
		];
	}

	/**
	 * The access levels the command hands out, and only those: the dialog offers the list the server holds
	 * ({@see ChangeObjectRightsCommand::ALLOWED_TASK_NAMES}), so a name that comes past the dialog is
	 * refused on the run instead of being written.
	 */
	public static function getTaskOptions(): array
	{
		$offeredTaskNames = ChangeObjectRightsCommand::ALLOWED_TASK_NAMES;

		if (!static::checkModules())
		{
			return [self::DEFAULT_TASK_NAME => (string)Loc::getMessage('BPDCHR_TASK_DISK_ACCESS_READ')];
		}

		$taskNamesOfPortal = array_column(Driver::getInstance()->getRightsManager()->getTasks(), 'NAME');

		$options = [];
		foreach ($offeredTaskNames as $taskName)
		{
			if (!in_array($taskName, $taskNamesOfPortal, true))
			{
				continue;
			}

			$options[$taskName] = (string)Loc::getMessage('BPDCHR_TASK_' . mb_strtoupper($taskName));
		}

		return $options ?: [self::DEFAULT_TASK_NAME => (string)Loc::getMessage('BPDCHR_TASK_DISK_ACCESS_READ')];
	}

	/** Changing rights is a setting only an administrator of the portal may make, as with the other activities of Disk. */
	public static function validateProperties($testProperties = [], ?CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];
		if ($user && !$user->isAdmin())
		{
			$errors[] = [
				'code' => 'AccessDenied',
				'parameter' => 'Admin',
				'message' => Loc::getMessage('BPDCHR_ERROR_ACCESS_DENIED'),
			];
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
	}

	protected function internalExecute(): ErrorCollection
	{
		$source = ObjectValueSource::tryFrom($this->getScalarProperty('ObjectSource'));
		if ($source === null)
		{
			return $this->makeErrors('BPDCHR_LOG_UNKNOWN_OBJECT_SOURCE');
		}

		// Changing rights on a guessed mode would hand out a right where a revoke was written.
		$mode = ObjectRightsChangeMode::tryFrom($this->getScalarProperty('Mode'));
		if ($mode === null)
		{
			return $this->makeErrors('BPDCHR_LOG_UNKNOWN_MODE');
		}

		$objectValue = $this->ObjectId;
		if (is_array($objectValue))
		{
			// A right is changed on one object, so a multiple file field naming several of them is
			// refused instead of being narrowed down to the first file silently.
			if (count($objectValue) > 1)
			{
				return $this->makeErrors('BPDCHR_LOG_MULTIPLE_OBJECTS');
			}

			$objectValue = $objectValue === [] ? null : reset($objectValue);
		}

		// The object is named in the journal only once the rights of the operator have been checked: a
		// record written earlier would answer whether an object of that number exists and how it is called
		// to everyone able to reach the journal of the process.
		$object = (new ObjectResolver())->resolve($objectValue, $source);
		if (!$object)
		{
			return $this->makeErrors('BPDCHR_LOG_OBJECT_NOT_FOUND');
		}

		$operatorId = (int)CBPHelper::extractUsers($this->Operator, $this->getDocumentId(), true);
		if ($operatorId <= 0)
		{
			return $this->makeErrors('BPDCHR_LOG_EMPTY_OPERATOR');
		}

		$recipients = (new AccessCodeMapper($this->getDocumentId()))->map((array)$this->Recipients);
		$this->logSkippedRecipients($recipients->skipped);
		$this->logWidenedRecipients($recipients->widened);

		if (!$recipients->codes)
		{
			return $this->makeErrors('BPDCHR_LOG_EMPTY_RECIPIENTS');
		}

		$taskName = $this->getScalarProperty('TaskName');

		$result = (new ChangeObjectRightsCommand(
			objectId: (int)$object->getId(),
			mode: $mode,
			taskName: $taskName,
			accessCodes: $recipients->codes,
			operatorId: $operatorId,
			workflowContext: $this->workflow !== null
				&& method_exists(CBPDocumentService::class, 'createDocumentOperationContext')
				? CBPDocumentService::createDocumentOperationContext($this->workflow)
				: [],
		))->run();

		if (!$result->isSuccess())
		{
			return $this->describeFailure($result->getErrorCollection());
		}

		$this->log((string)Loc::getMessage('BPDCHR_LOG_OBJECT_RESOLVED', [
			'#NAME#' => (string)$object->getName(),
			'#ID#' => (string)$object->getId(),
		]));
		$this->logOutcome($mode, $taskName, $result->getData());
		if ($mode === ObjectRightsChangeMode::Revoke)
		{
			$this->logOperatorAmongRecipients($operatorId, (array)($result->getData()['applied'] ?? []));
		}

		return new ErrorCollection();
	}

	/**
	 * The access level stands beside every code: the level comes from a value of the document on the run,
	 * and the journal of the process is the only place a change of rights can be read back from.
	 *
	 * @param array{applied: string[], skipped: array<string, string>} $data
	 */
	private function logOutcome(ObjectRightsChangeMode $mode, string $taskName, array $data): void
	{
		$appliedMessageId = $mode === ObjectRightsChangeMode::Grant
			? 'BPDCHR_LOG_GRANTED'
			: 'BPDCHR_LOG_REVOKED'
		;
		$taskTitle = static::getTaskTitle($taskName);

		$skipped = $data['skipped'] ?? [];

		foreach ($data['applied'] ?? [] as $accessCode)
		{
			// A code whose right of the base domain has gone while a right of a sharing stays is one
			// outcome and not two: two records of it would read as a contradiction.
			if (($skipped[$accessCode] ?? null) === ChangeObjectRightsCommand::SKIP_REASON_OTHER_DOMAIN)
			{
				unset($skipped[$accessCode]);
				$this->log((string)Loc::getMessage(
					'BPDCHR_LOG_REVOKED_OTHER_DOMAIN_LEFT',
					['#CODE#' => $accessCode, '#LEVEL#' => $taskTitle],
				));

				continue;
			}

			$this->log((string)Loc::getMessage(
				$appliedMessageId,
				['#CODE#' => $accessCode, '#LEVEL#' => $taskTitle],
			));
		}

		// A right the command has left standing - one of a parent folder, of a prohibition or of a
		// sharing - is named here: saying nothing about it would read as a change that has been made.
		foreach ($skipped as $accessCode => $reason)
		{
			$messageId = self::getSkipMessageIds()[$reason] ?? 'BPDCHR_LOG_SKIPPED_RECIPIENT';

			$this->log((string)Loc::getMessage($messageId, ['#CODE#' => (string)$accessCode]));
		}
	}

	/** @return array<string, string> */
	private static function getSkipMessageIds(): array
	{
		return [
			ChangeObjectRightsCommand::SKIP_REASON_INHERITED => 'BPDCHR_LOG_SKIPPED_INHERITED',
			ChangeObjectRightsCommand::SKIP_REASON_PROHIBITED => 'BPDCHR_LOG_SKIPPED_PROHIBITED',
			ChangeObjectRightsCommand::SKIP_REASON_OTHER_DOMAIN => 'BPDCHR_LOG_SKIPPED_OTHER_DOMAIN',
			ChangeObjectRightsCommand::SKIP_REASON_NOT_APPLIED => 'BPDCHR_LOG_SKIPPED_NOT_APPLIED',
		];
	}

	private static function getTaskTitle(string $taskName): string
	{
		return static::getTaskOptions()[$taskName]
			?? (string)Loc::getMessage('BPDCHR_TASK_' . mb_strtoupper($taskName))
			?: $taskName
		;
	}

	/**
	 * A code the operator falls under takes the right away from the operator as well - their own code,
	 * their department, everyone of the portal. The journal names it: a template that locks its own
	 * operator out is otherwise read as a plain revoke.
	 *
	 * @param string[] $accessCodes
	 */
	private function logOperatorAmongRecipients(int $operatorId, array $accessCodes): void
	{
		foreach ($this->findAccessCodesOfUser($operatorId, $accessCodes) as $accessCode)
		{
			$this->log((string)Loc::getMessage(
				'BPDCHR_LOG_OPERATOR_AMONG_RECIPIENTS',
				['#CODE#' => $accessCode],
			));
		}
	}

	/**
	 * @param string[] $accessCodes
	 * @return string[]
	 */
	private function findAccessCodesOfUser(int $userId, array $accessCodes): array
	{
		if ($userId <= 0 || !$accessCodes)
		{
			return [];
		}

		$rows = UserAccessTable::getList([
			'select' => ['ACCESS_CODE'],
			'filter' => ['=USER_ID' => $userId, '@ACCESS_CODE' => $accessCodes],
		])->fetchAll();

		return array_values(array_unique(array_column($rows, 'ACCESS_CODE')));
	}

	/**
	 * A recipient disk writes under a wider code than the one chosen - a department with the ones below
	 * it - is named in the journal: the right reaches more people than the template names, and that has
	 * to be readable afterwards.
	 *
	 * @param array<string, string> $widened Value as it came, with the access code written for it.
	 */
	private function logWidenedRecipients(array $widened): void
	{
		foreach ($widened as $value => $accessCode)
		{
			$this->log((string)Loc::getMessage('BPDCHR_LOG_WIDENED_RECIPIENT', [
				'#VALUE#' => (string)$value,
				'#CODE#' => $accessCode,
			]));
		}
	}

	/** @param array<string, string> $skipped */
	private function logSkippedRecipients(array $skipped): void
	{
		$messageIdsByReason = [
			AccessCodeMapper::SKIP_REASON_EMPTY_HR_NODE => 'BPDCHR_LOG_SKIPPED_EMPTY_NODE',
			AccessCodeMapper::SKIP_REASON_TOO_MANY_USERS => 'BPDCHR_LOG_SKIPPED_TOO_MANY_USERS',
			AccessCodeMapper::SKIP_REASON_TOO_MANY_CODES => 'BPDCHR_LOG_SKIPPED_TOO_MANY_CODES',
		];

		foreach ($skipped as $value => $reason)
		{
			$messageId = $messageIdsByReason[$reason] ?? 'BPDCHR_LOG_SKIPPED_RECIPIENT';

			$this->log((string)Loc::getMessage($messageId, ['#CODE#' => (string)$value]));
		}
	}

	private function describeFailure(ErrorCollection $errors): ErrorCollection
	{
		$messageIdsByCode = [
			ChangeObjectRightsCommand::ERROR_OBJECT_NOT_FOUND => 'BPDCHR_LOG_OBJECT_NOT_FOUND',
			ChangeObjectRightsCommand::ERROR_ACCESS_DENIED => 'BPDCHR_LOG_ACCESS_DENIED',
			ChangeObjectRightsCommand::ERROR_UNKNOWN_TASK => 'BPDCHR_LOG_UNKNOWN_TASK',
			ChangeObjectRightsCommand::ERROR_OBJECT_IN_TRASH => 'BPDCHR_LOG_OBJECT_IN_TRASH',
			ChangeObjectRightsCommand::ERROR_NO_INTERNAL_RIGHTS => 'BPDCHR_LOG_NO_INTERNAL_RIGHTS',
		];

		foreach ($errors as $error)
		{
			$messageId = $messageIdsByCode[(string)$error->getCode()] ?? null;
			if ($messageId !== null)
			{
				return $this->makeErrors($messageId);
			}
		}

		return $this->makeErrors('BPDCHR_LOG_COULD_NOT_CHANGE');
	}

	private function makeErrors(string $messageId): ErrorCollection
	{
		return new ErrorCollection([new Error((string)Loc::getMessage($messageId))]);
	}

	/** A property of a template answers as an array as readily as a scalar, and a mode is one value. */
	private function getScalarProperty(string $propertyId): string
	{
		$value = $this->{$propertyId};
		if (is_array($value))
		{
			$value = reset($value);
		}

		return is_scalar($value) ? (string)$value : '';
	}
}
