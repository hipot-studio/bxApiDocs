<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Tasks\Integration\BizProc\NodeFilter\TargetDocumentResolverTrait;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Command\Task\Result\AddResultCommand;
use Bitrix\Tasks\V2\Public\Service\Access\ResultAccessService;

if (!Loader::includeModule('tasks'))
{
	return;
}

class CBPTasksAddResultActivity extends CBPActivity implements IBPConfigurableActivity
{
	use TargetDocumentResolverTrait;

	private const RESULT_TEXT_MAX_LENGTH = 1_048_576;
	private const WHITESPACE_PATTERN = '/^[\x{0009}-\x{000D}\p{Z}\x{FEFF}]*$/uD';
	private const USER_CODE_PATTERN = '/^user_\d+$/';

	/**
	 * Roles of a task document that stand for a single person
	 * ({@see \Bitrix\Tasks\Integration\BizProc\Document\Task::getUsersFromUserGroup}). A role behind which
	 * several people stand, scrum_team among them, cannot name an author and is refused like a group code.
	 */
	private const SINGLE_USER_DOCUMENT_ROLES = ['responsible', 'scrum_master', 'scrum_owner'];

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'ResultText' => null,
			'ModifiedBy' => null,
			'ErrorMessage' => '',
		];
	}

	public function ReInitialize()
	{
		parent::ReInitialize();

		$this->ErrorMessage = '';
	}

	public function Execute()
	{
		try
		{
			$evaluatedText = $this->ParseValue($this->getRawProperty('ResultText'), 'text');
		}
		catch (Throwable)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_INPUT_ERROR'));

			return CBPActivityExecutionStatus::Closed;
		}

		if ($evaluatedText === null)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		if (!is_scalar($evaluatedText))
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_INVALID_TYPE'));

			return CBPActivityExecutionStatus::Closed;
		}

		$text = (string)$evaluatedText;
		if (strlen($text) > self::RESULT_TEXT_MAX_LENGTH)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_TEXT_TOO_LONG'));

			return CBPActivityExecutionStatus::Closed;
		}

		if (preg_match('//u', $text) !== 1)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_INVALID_TEXT_ENCODING'));

			return CBPActivityExecutionStatus::Closed;
		}

		$whitespaceMatch = preg_match(self::WHITESPACE_PATTERN, $text);
		if ($whitespaceMatch === false)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_INVALID_TEXT_ENCODING'));

			return CBPActivityExecutionStatus::Closed;
		}

		if ($whitespaceMatch === 1)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		if (!Loader::includeModule('tasks'))
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_MODULE_NOT_AVAILABLE'));

			return CBPActivityExecutionStatus::Closed;
		}

		$documentId = $this->resolveTargetDocumentId();
		$taskId = (int)($documentId[2] ?? 0);
		if (($documentId[0] ?? null) !== 'tasks' || $taskId <= 0)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_TARGET_UNAVAILABLE'));

			return CBPActivityExecutionStatus::Closed;
		}

		[$actorId, $authorError] = $this->resolveResultAuthor();
		if ($actorId <= 0)
		{
			$this->recordActivityError($authorError ?? GetMessage('TASKS_ADD_RESULT_ACTOR_UNAVAILABLE'));

			return CBPActivityExecutionStatus::Closed;
		}

		$result = new Entity\Result(taskId: $taskId, text: $text);

		try
		{
			$canSave = $this->canSaveResult($actorId, $result);
		}
		catch (Throwable)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_ACCESS_CHECK_ERROR'));

			return CBPActivityExecutionStatus::Closed;
		}

		if (!$canSave)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_ACCESS_DENIED'));

			return CBPActivityExecutionStatus::Closed;
		}

		try
		{
			$commandResult = $this->runAddResultCommand($result, $actorId);
		}
		catch (CommandValidationException)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_COMMAND_VALIDATION_ERROR'));

			return CBPActivityExecutionStatus::Closed;
		}
		catch (Throwable)
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_RESULT_OUTCOME_UNKNOWN'));

			return CBPActivityExecutionStatus::Closed;
		}

		if (!$commandResult->isSuccess())
		{
			$this->recordActivityError(GetMessage('TASKS_ADD_RESULT_RESULT_OUTCOME_UNKNOWN'));
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public static function ValidateProperties(
		$arTestProperties = [],
		CBPWorkflowTemplateUser $user = null,
	)
	{
		$errors = [];

		$modifiedBy = $arTestProperties['ModifiedBy'] ?? null;
		if (CBPHelper::isEmptyValue($modifiedBy))
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => 'ModifiedBy',
				'message' => GetMessage('TASKS_ADD_RESULT_MODIFIED_BY_EMPTY_PROP'),
			];
		}
		elseif (!self::isAllowedAuthorValue($modifiedBy))
		{
			$errors[] = [
				'code' => 'ErrorValue',
				'parameter' => 'ModifiedBy',
				'message' => GetMessage('TASKS_ADD_RESULT_MODIFIED_BY_NOT_SINGLE'),
			];
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	public static function GetPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = '',
	)
	{
		if (!Loader::includeModule('tasks'))
		{
			return false;
		}

		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(
			__FILE__,
			[
				'documentType' => $documentType,
				'activityName' => $activityName,
				'workflowTemplate' => $arWorkflowTemplate,
				'workflowParameters' => $arWorkflowParameters,
				'workflowVariables' => $arWorkflowVariables,
				'currentValues' => $arCurrentValues,
				'formName' => $formName,
				'siteId' => $siteId,
			],
		);
		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function GetPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$errors,
	)
	{
		$errors = [];

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName(
			$arWorkflowTemplate,
			$activityName,
		);
		$existingProperties = is_array($currentActivity['Properties'] ?? null)
			? $currentActivity['Properties']
			: []
		;
		$rawModifiedBy = $arCurrentValues['modified_by'] ?? '';
		$modifiedBy = is_string($rawModifiedBy)
			? CBPHelper::UsersStringToArray($rawModifiedBy, $documentType, $errors)
			: []
		;
		if (count($errors) > 0)
		{
			return false;
		}

		$properties = array_merge(
			$existingProperties,
			[
				'ResultText' => $arCurrentValues['result_text'] ?? null,
				'ModifiedBy' => $modifiedBy,
			],
		);

		$errors = static::ValidateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser),
		);
		if (count($errors) > 0)
		{
			return false;
		}

		$currentActivity['Properties'] = $properties;

		return true;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'ResultText' => [
				'Name' => GetMessage('TASKS_ADD_RESULT_TEXT'),
				'Description' => GetMessage('TASKS_ADD_RESULT_TEXT_DESCRIPTION'),
				'FieldName' => 'result_text',
				'Type' => 'text',
				'Required' => false,
			],
			'ModifiedBy' => [
				'Name' => GetMessage('TASKS_ADD_RESULT_MODIFIED_BY'),
				'Description' => GetMessage('TASKS_ADD_RESULT_MODIFIED_BY_DESCRIPTION'),
				'FieldName' => 'modified_by',
				'Type' => 'user',
				'Required' => true,
			],
		];
	}

	/**
	 * Only the explicit setting names the result author: an automatic workflow may have no starter,
	 * so the fallback to the workflow starter of the trait is disabled here.
	 */
	protected function resolveTargetAccessActorId(): int
	{
		[$actorId] = $this->resolveResultAuthor();

		return $actorId;
	}

	protected function canSaveResult(int $userId, Entity\Result $result): bool
	{
		return (new ResultAccessService())->canSave($userId, $result);
	}

	protected function runAddResultCommand(Entity\Result $result, int $userId): Result
	{
		return (new AddResultCommand($result, $userId, false, false))->run();
	}

	/**
	 * The setting is resolved against the target document, the one the result is written to, so this runs
	 * only after {@see TargetDocumentResolverTrait::resolveTargetDocumentId()} has named that document.
	 *
	 * @return array{0: int, 1: string|null} the author and the reason they could not be named
	 */
	private function resolveResultAuthor(): array
	{
		try
		{
			$modifiedBy = $this->ModifiedBy;
			if (CBPHelper::isEmptyValue($modifiedBy))
			{
				return [0, null];
			}

			// An expression is substituted before it is read here, so the rule that guards saving guards
			// the run as well and a value the dialog refuses never becomes an author behind its back.
			if (!self::isAllowedAuthorValue($modifiedBy))
			{
				return [0, GetMessage('TASKS_ADD_RESULT_ACTOR_NOT_SINGLE')];
			}

			$authorIds = CBPHelper::ExtractUsers($modifiedBy, $this->getDocumentId());
		}
		catch (Throwable)
		{
			return [0, GetMessage('TASKS_ADD_RESULT_ACTOR_RESOLVE_ERROR')];
		}

		// Keeps the invariant if a role of the document type ever grows a second member.
		if (count($authorIds) > 1)
		{
			return [0, GetMessage('TASKS_ADD_RESULT_ACTOR_NOT_SINGLE')];
		}

		return [(int)($authorIds[0] ?? 0), null];
	}

	private static function isAllowedAuthorValue(mixed $value): bool
	{
		$codes = is_array($value) ? array_values($value) : [$value];
		if (count($codes) !== 1 || !is_string($codes[0]))
		{
			return false;
		}

		$code = trim($codes[0]);

		return static::isExpression($code)
			|| preg_match(self::USER_CODE_PATTERN, $code) === 1
			|| in_array($code, self::SINGLE_USER_DOCUMENT_ROLES, true)
		;
	}

	private function recordActivityError(string $message): void
	{
		$this->ErrorMessage = $message;
		$this->WriteToTrackingService($message, 0, CBPTrackingType::Error);
	}
}
