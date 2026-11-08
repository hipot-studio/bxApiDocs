<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\FileUploader\TaskUploaderController;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\UI\FileUploader\PendingFileCollection;
use Bitrix\UI\FileUploader\PendingFileStatus;
use Bitrix\UI\FileUploader\TempFileTable;
use Bitrix\UI\FileUploader\Uploader;

/**
 * A file field of a running task: its code, its current value and the uploader whose tokens it signs.
 *
 * There is no context when the task is gone, is not running or names no file field under the given key -
 * a caller falls back to its previous behaviour, so nothing here raises.
 */
final class TaskFileUploaderContext
{
	/**
	 * The id of the task in the request the task form is read from. The card puts it there out of the task
	 * it has already checked (CBPRequestInformationActivity::getTaskResponse), but the same read serves the
	 * settings form of an activity in the designer, where the whole request is a client one - so the id is
	 * never trusted: BaseType\File::acceptTaskFileIds() checks whose task it is.
	 */
	public const REQUEST_TASK_ID_KEY = 'BIZPROC_TASK_ID';

	/** The card knows a field by the name of its form control, the uploader - by the code of the field. */
	private const KEY_CONTROL_NAME = 'FieldId';
	private const KEY_FIELD_NAME = 'Id';

	/**
	 * How many tasks the memoized answers below are kept for. A request of the card asks about one task,
	 * a group pass about the tasks of its set; a process performing task after task (a queue, a stepper)
	 * would otherwise hold every one of them until it ends.
	 */
	private const MAX_CACHED_TASKS = 50;

	/**
	 * Controls of a task parsed out of ACTIVITY and PARAMETERS, by the id of the task. One decision asks
	 * for them once per file field and once more per value of a multiple field.
	 */
	private static array $taskFields = [];

	/**
	 * Rows of the tasks read here, by the id of the task; null stands for a task that is gone or is not
	 * running any more. A multiple field is read value by value (Base::extractValueMultiple), and every
	 * value would otherwise cost a select of the same row.
	 *
	 * The row lives no longer than the decision it was read for: performing a task turns its status, so
	 * TaskService::doTask forgets it right past CBPDocument::PostTaskForm and the next decision of a group
	 * pass reads the status anew.
	 */
	private static array $taskRows = [];

	/**
	 * Uploads already resolved, by the task, the field and the token. One decision asks the uploader for
	 * the same token twice - once for the value of the field (BaseType\File::extractValue) and once to pin
	 * it (Controller\Task) - and every ask is a select over b_ui_file_uploader_temp_file.
	 */
	private static array $resolvedUploads = [];

	/** Answers of the pending-upload check, by the task, the field, the user and the id of the file. */
	private static array $pendingFileIds = [];

	/**
	 * Files this request itself saved for a field of a task, by the task and the field. An upload of the
	 * client is known by the temporary row behind it (filterPendingFileIds), but a file the server builds
	 * out of the bytes of a REST call (BaseType\File::internalizeValue) has no such row and never had a
	 * token: what is trusted about it is that this request saved it for this field.
	 */
	private static array $serverSavedFileIds = [];

	/** The ids of the tasks above in the order they were first asked about, for MAX_CACHED_TASKS. */
	private static array $cachedTaskIds = [];

	private function __construct(
		private readonly int $taskId,
		private readonly array $field,
	)
	{
	}

	/**
	 * The id read here may be an id the client sent, so a caller giving anything out of such a context
	 * asks isCurrentUserPerformer() first.
	 */
	public static function createFromRequest(array $request, string $controlName): ?self
	{
		$taskId = $request[self::REQUEST_TASK_ID_KEY] ?? null;

		return self::createFromTask(is_scalar($taskId) ? (int)$taskId : 0, $controlName);
	}

	public static function createFromTask(int $taskId, string $controlName): ?self
	{
		return self::createFromTaskBy($taskId, self::KEY_CONTROL_NAME, $controlName);
	}

	/**
	 * For a caller that knows the code of the field and not the name of its control: the uploader of the
	 * field is given the code in its options and never sees the form.
	 */
	public static function createFromTaskByFieldName(int $taskId, string $fieldName): ?self
	{
		return self::createFromTaskBy($taskId, self::KEY_FIELD_NAME, $fieldName);
	}

	/**
	 * The same context out of a row the caller has read and has found running: the status is not checked
	 * here, so whoever has not checked it asks createFromTask() instead. The row is expected to carry
	 * ACTIVITY and PARAMETERS.
	 */
	public static function createFromRunningTask(int $taskId, array $task, string $controlName): ?self
	{
		return self::createFromRunningTaskBy($taskId, $task, self::KEY_CONTROL_NAME, $controlName);
	}

	/** For a process that outlives a request: a test writing tasks of its own, a queue performing them. */
	public static function resetCaches(): void
	{
		self::$taskFields = [];
		self::$taskRows = [];
		self::$resolvedUploads = [];
		self::$pendingFileIds = [];
		self::$serverSavedFileIds = [];
		self::$cachedTaskIds = [];
	}

	/**
	 * Forgets the row of a task whose status could have turned - the decision that was performing it is
	 * over. The controls parsed out of the row are left as they are: a task keeps the fields it asked for
	 * however it ended, and the tokens and the ids remembered for them belong to the request either way.
	 */
	public static function forgetTask(int $taskId): void
	{
		unset(self::$taskRows[$taskId]);
	}

	/**
	 * Remembers a file the server has just saved for the given field of the given task. Nothing is granted
	 * by it alone: the rule of the type still asks for a running task naming the field (createFromTask)
	 * and for a caller performing it (isCurrentUserPerformer).
	 */
	public static function registerServerSavedFileId(int $taskId, string $fieldName, int $fileId): void
	{
		if ($taskId > 0 && $fieldName !== '' && $fileId > 0)
		{
			self::rememberTask($taskId);
			self::$serverSavedFileIds[$taskId][$fieldName][$fileId] = true;
		}
	}

	/**
	 * Half of the rule of a file field - the half the row answers: the task runs and names a file field
	 * under the given key. The other half is isCurrentUserPerformer(), asked separately by every caller.
	 */
	private static function createFromTaskBy(int $taskId, string $key, string $value): ?self
	{
		if (!self::isAskable($taskId, $value))
		{
			return null;
		}

		$task = self::readRunningTask($taskId);

		return $task === null ? null : self::build($taskId, $task, $key, $value);
	}

	/**
	 * The row of the task while it runs, or null while it does not. Read once per task and kept only until
	 * the decision that was reading it is over - see $taskRows.
	 */
	private static function readRunningTask(int $taskId): ?array
	{
		if (array_key_exists($taskId, self::$taskRows))
		{
			return self::$taskRows[$taskId];
		}

		$task = \CBPTaskService::getList(
			arFilter: ['ID' => $taskId],
			arSelectFields: ['ACTIVITY', 'PARAMETERS', 'STATUS']
		)->fetch();

		$running = ($task && (int)$task['STATUS'] === \CBPTaskStatus::Running) ? $task : null;

		self::rememberTask($taskId);
		self::$taskRows[$taskId] = $running;

		return $running;
	}

	private static function createFromRunningTaskBy(int $taskId, array $task, string $key, string $value): ?self
	{
		return self::isAskable($taskId, $value) ? self::build($taskId, $task, $key, $value) : null;
	}

	private static function isAskable(int $taskId, string $value): bool
	{
		return $taskId > 0 && $value !== '' && Loader::includeModule('ui');
	}

	private static function build(int $taskId, array $task, string $key, string $value): ?self
	{
		$field = self::findFileField($taskId, $task, $key, $value);

		return $field === null ? null : new self($taskId, $field);
	}

	/**
	 * Public for the callers that walk the fields of a task themselves and would otherwise repeat the
	 * parse. No user is passed to it - whoever needs the controls as one certain user sees them asks
	 * CBPDocument::getTaskControls() with that user and keeps the answer out of here.
	 *
	 * A row carrying neither ACTIVITY nor PARAMETERS is no answer about the task and is not remembered as
	 * one, so that the next caller of the request - one holding the whole row - still gets the fields.
	 */
	public static function getTaskFields(int $taskId, array $task): array
	{
		if (array_key_exists($taskId, self::$taskFields))
		{
			return self::$taskFields[$taskId];
		}

		if (!array_key_exists('ACTIVITY', $task) || !array_key_exists('PARAMETERS', $task))
		{
			return [];
		}

		$controls = \CBPDocument::getTaskControls($task);
		$fields = is_array($controls) ? ($controls['FIELDS'] ?? []) : [];

		self::rememberTask($taskId);
		self::$taskFields[$taskId] = is_array($fields) ? $fields : [];

		return self::$taskFields[$taskId];
	}

	public function getTaskId(): int
	{
		return $this->taskId;
	}

	public function getFieldName(): string
	{
		return $this->field['Id'];
	}

	public function getFieldValue(): mixed
	{
		return $this->field['Default'] ?? null;
	}

	/**
	 * Whether the task is a task of the current user. The context is built out of an id a caller may have
	 * taken from a request, so a caller giving out anything about the file of the field - its name, its
	 * size, the link it is served by - asks this first. The same set of users the uploader of the field
	 * checks its own calls against (TaskUploaderController::canView).
	 */
	public function isCurrentUserPerformer(): bool
	{
		$userId = (int)(CurrentUser::get()?->getId() ?? 0);

		return $userId > 0 && in_array($userId, \CBPTaskService::getTaskUserIds($this->taskId), true);
	}

	public function getUploaderController(): TaskUploaderController
	{
		return new TaskUploaderController($this->getUploaderControllerOptions());
	}

	/**
	 * The options the uploader of this field is addressed by, ready to be handed to the client: the keys
	 * belong to the controller, so nobody drawing the field spells them out for itself.
	 *
	 * @return array{taskId: int, fieldName: string}
	 */
	public function getUploaderControllerOptions(): array
	{
		return [
			TaskUploaderController::OPTION_TASK_ID => $this->taskId,
			TaskUploaderController::OPTION_FIELD_NAME => $this->getFieldName(),
		];
	}

	/**
	 * @return int[] ids of the b_file records behind the tokens; a token that is unknown, forged or still
	 *   uploading has no record and is left out
	 */
	public function getFileIds(array $tokens): array
	{
		return $this->getPendingFiles($tokens)->getFileIds();
	}

	public function resolveToken(string $token): ?int
	{
		return $this->getFileIds([$token])[0] ?? null;
	}

	/**
	 * @return string[] the tokens the uploader left temporary: it pins a file only while the file is
	 *   PENDING and says nothing of one it passed over, so the answer is read off the statuses
	 */
	public function makePersistent(array $tokens): array
	{
		$pendingFiles = $this->getPendingFiles($tokens);
		$fileIds = $pendingFiles->getFileIds();
		$pendingFiles->makePersistent();

		$leftTemporary = [];
		foreach ($pendingFiles->getAll() as $pendingFile)
		{
			if ($pendingFile->getStatus() !== PendingFileStatus::COMMITTED)
			{
				$leftTemporary[] = $pendingFile->getId();
			}
		}

		$fieldName = $this->getFieldName();

		// the temporary rows behind these tokens are gone, so nothing here may answer for them any more
		foreach ($tokens as $token)
		{
			if (is_string($token))
			{
				unset(self::$resolvedUploads[$this->taskId][$fieldName][$token]);
			}
		}

		unset(self::$pendingFileIds[$this->taskId][$fieldName]);

		return $leftTemporary;
	}

	/** @return int[] ids of accepted uploads whose temporary rows could not be pinned */
	public function makeFileIdsPersistent(array $fileIds): array
	{
		$pendingIds = $this->filterPendingFileIds($fileIds);
		if (!$pendingIds)
		{
			return [];
		}

		$userId = (int)(CurrentUser::get()?->getId() ?? 0);
		$leftTemporary = array_fill_keys($pendingIds, true);
		$uploads = TempFileTable::getList([
			'filter' => [
				'@FILE_ID' => $pendingIds,
				'=CONTROLLER' => $this->getUploaderController()->getName(),
				'=UPLOADED' => true,
				'=CREATED_BY' => $userId,
			],
		]);

		try
		{
			while ($upload = $uploads->fetchObject())
			{
				$options = $upload->getControllerOptions();
				if (
					is_array($options)
					&& (int)($options[TaskUploaderController::OPTION_TASK_ID] ?? 0) === $this->taskId
					&& (string)($options[TaskUploaderController::OPTION_FIELD_NAME] ?? '') === $this->getFieldName()
				)
				{
					$upload->makePersistent();
					unset($leftTemporary[$upload->getFileId()]);
				}
			}
		}
		finally
		{
			unset(self::$pendingFileIds[$this->taskId][$this->getFieldName()]);
		}

		return array_keys($leftTemporary);
	}

	/**
	 * Whether the file is an upload of this field waiting to be pinned. The mobile card resolves the token
	 * of its upload into an id before the value reaches the server, so the row of the upload is what is
	 * left to tell it from a file of somebody else. makePersistent() takes that row away.
	 */
	public function hasPendingFileId(int $fileId): bool
	{
		return in_array($fileId, $this->filterPendingFileIds([$fileId]), true);
	}

	/**
	 * Whether the file is one this request saved for this field of this task. A REST call sends the bytes
	 * of the file and not an id, so the value of the field names a record the server made a moment
	 * earlier - no value of the field yet and no upload of the uploader either.
	 */
	public function hasServerSavedFileId(int $fileId): bool
	{
		return self::$serverSavedFileIds[$this->taskId][$this->getFieldName()][$fileId] ?? false;
	}

	/**
	 * The same rule for a set of ids in one select: a multiple field is asked about every value it holds.
	 *
	 * @param int[] $fileIds
	 * @return int[] those of the ids that are uploads of this field waiting to be pinned
	 */
	public function filterPendingFileIds(array $fileIds): array
	{
		$userId = (int)(CurrentUser::get()?->getId() ?? 0);
		if ($userId <= 0)
		{
			return [];
		}

		$fieldName = $this->getFieldName();
		$known = self::$pendingFileIds[$this->taskId][$fieldName][$userId] ?? [];

		$candidates = [];
		foreach ($fileIds as $fileId)
		{
			$fileId = (int)$fileId;
			if ($fileId > 0 && !array_key_exists($fileId, $known))
			{
				$candidates[$fileId] = $fileId;
			}
		}

		if ($candidates)
		{
			$uploads = TempFileTable::getList([
				'select' => ['FILE_ID', 'CONTROLLER_OPTIONS'],
				'filter' => [
					'@FILE_ID' => array_values($candidates),
					'=CONTROLLER' => $this->getUploaderController()->getName(),
					'=UPLOADED' => true,
					'=CREATED_BY' => $userId,
				],
			]);

			$known += array_fill_keys($candidates, false);

			foreach ($uploads as $upload)
			{
				$options = $upload['CONTROLLER_OPTIONS'];
				if (
					is_array($options)
					&& (int)($options[TaskUploaderController::OPTION_TASK_ID] ?? 0) === $this->taskId
					&& (string)($options[TaskUploaderController::OPTION_FIELD_NAME] ?? '') === $fieldName
				)
				{
					$known[(int)$upload['FILE_ID']] = true;
				}
			}

			self::rememberTask($this->taskId);
			self::$pendingFileIds[$this->taskId][$fieldName][$userId] = $known;
		}

		$pending = [];
		foreach ($fileIds as $fileId)
		{
			$fileId = (int)$fileId;
			if ($fileId > 0 && ($known[$fileId] ?? false))
			{
				$pending[] = $fileId;
			}
		}

		return $pending;
	}

	/**
	 * One token per call to the uploader on purpose: it reads the signature of a token before the next one
	 * and raises on a signature that is not even hexadecimal, so a batch would lose the valid tokens
	 * standing behind a forged one.
	 */
	private function getPendingFiles(array $tokens): PendingFileCollection
	{
		$pendingFiles = new PendingFileCollection();
		$fieldName = $this->getFieldName();
		$uploader = null;

		foreach ($tokens as $token)
		{
			if (!is_string($token) || $token === '')
			{
				continue;
			}

			$resolved = self::$resolvedUploads[$this->taskId][$fieldName][$token] ?? null;
			if ($resolved !== null)
			{
				$pendingFiles->add($resolved);

				continue;
			}

			$uploader ??= new Uploader($this->getUploaderController());

			try
			{
				foreach ($uploader->getPendingFiles([$token]) as $pendingFile)
				{
					$pendingFiles->add($pendingFile);

					// a token that resolved to nothing is not remembered: the answer of the uploader to it
					// carries an error and is the very thing a caller is expected to see again
					if ($pendingFile->isValid())
					{
						self::rememberTask($this->taskId);
						self::$resolvedUploads[$this->taskId][$fieldName][$token] = $pendingFile;
					}
				}
			}
			catch (BadSignatureException)
			{
				// a signature that is not hexadecimal is as good as an unknown token here
			}
		}

		return $pendingFiles;
	}

	/**
	 * Keeps the answers of the last MAX_CACHED_TASKS tasks, the oldest dropped whole: a task nothing asks
	 * about any more is a task nothing is remembered of.
	 */
	private static function rememberTask(int $taskId): void
	{
		$known = array_search($taskId, self::$cachedTaskIds, true);
		if ($known !== false)
		{
			return;
		}

		self::$cachedTaskIds[] = $taskId;

		while (count(self::$cachedTaskIds) > self::MAX_CACHED_TASKS)
		{
			$oldest = (int)array_shift(self::$cachedTaskIds);

			unset(
				self::$taskFields[$oldest],
				self::$taskRows[$oldest],
				self::$resolvedUploads[$oldest],
				self::$pendingFileIds[$oldest],
				self::$serverSavedFileIds[$oldest],
			);
		}
	}

	/**
	 * The one place a control of the task is told to be a file field of it. The value is matched against
	 * the controls whole and never taken apart: the prefix the activity builds a control name with belongs
	 * to the activity. Whichever key it came in by, the field found carries a code of its own - the code
	 * the uploader of the field signs its tokens with.
	 */
	private static function findFileField(int $taskId, array $task, string $key, string $value): ?array
	{
		foreach (self::getTaskFields($taskId, $task) as $field)
		{
			if (
				is_array($field)
				&& ($field[$key] ?? null) === $value
				&& is_string($field['Id'] ?? null)
				&& $field['Id'] !== ''
				&& self::isFileField($task, $field)
			)
			{
				return $field;
			}
		}

		return null;
	}

	/**
	 * A field is a file field by the base type its document service gives it and not by the name of its
	 * type: a virtual document names its file type "F", a list field of the disk - "S:DiskFile", and both
	 * answer "file" here. It is the same rule the decision itself is read by
	 * (Controller\Task::getFileControlNames, Api\Service\TaskService::carriesFileFieldValue), so a field
	 * the one takes for a file field is a field the other takes too.
	 *
	 * Asked of the field the key has already matched and not of every control of the task: resolving a
	 * type is a walk through the document service.
	 */
	private static function isFileField(array $task, array $field): bool
	{
		$documentType = $task['PARAMETERS']['DOCUMENT_TYPE'] ?? null;

		// a field with no type is not resolved at all, as in CBPDocumentService::getFieldInputControl()
		if (!is_array($documentType) || (string)($field['Type'] ?? '') === '')
		{
			return false;
		}

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();

		return $documentService->getFieldTypeObject($documentType, $field)?->getBaseType() === FieldType::FILE;
	}
}
