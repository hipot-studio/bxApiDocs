<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\FileUploader;

use Bitrix\Bizproc\Internal\Integration\UI\TaskFileUploaderContext;
use Bitrix\UI\FileUploader\CommitOptions;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;

class TaskUploaderController extends UploaderController
{
	public const OPTION_TASK_ID = 'taskId';
	public const OPTION_FIELD_NAME = 'fieldName';

	/**
	 * Every option this controller knows; the rest of what a caller sends is dropped. The options are the
	 * salt of the upload token signature (Uploader::getTokenSalt mixes all of them in), so a key nothing
	 * here reads would still move the salt and cost the files already in a dropzone their tokens.
	 */
	private const KNOWN_OPTIONS = [
		self::OPTION_TASK_ID,
		self::OPTION_FIELD_NAME,
	];

	/** Derived from OPTION_FIELD_NAME by the constructor rather than sent. */
	private const OPTION_FIELD_ID = 'fieldId';

	/**
	 * The subdirectory of the old scheme of the field: Bizproc\BaseType\File::extractValue saves an uploaded
	 * file with \CFile::saveFile($value, 'bizproc_wf'), so both schemes share one place on disk.
	 */
	private const SAVE_PATH = 'bizproc_wf';

	private ?bool $hasRights = null;

	public function __construct(array $options)
	{
		$options = array_intersect_key($options, array_flip(self::KNOWN_OPTIONS));
		$options[self::OPTION_TASK_ID] = (int)($options[self::OPTION_TASK_ID] ?? 0);

		$fieldName = $options[self::OPTION_FIELD_NAME] ?? '';
		$options[self::OPTION_FIELD_ID] = is_scalar($fieldName) ? (string)$fieldName : '';

		parent::__construct($options);
	}

	public function isAvailable(): bool
	{
		return true;
	}

	/**
	 * The limit is set here and not taken from the options: they are the salt of the token signature, and
	 * a limit travelling in them would tie the tokens to the php settings of the request. The value is the
	 * ceiling the field had under the old scheme, where the file reached the server as a single form post.
	 */
	public function getConfiguration(): Configuration
	{
		return (new Configuration())->setMaxFileSize(Configuration::getPhpMaxUploadSize());
	}

	public function canUpload(): bool
	{
		return $this->hasRights();
	}

	public function canView(): bool
	{
		return $this->hasRights();
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		// field values have no ownership registry, so no persistent file may be claimed as own
	}

	/**
	 * The other side of ownership: nothing here owns a persistent file, and the files of these fields are
	 * served by bizproc.file.show rather than by the uploader, so its own removal path is unused.
	 */
	public function canRemove(): bool
	{
		return false;
	}

	public function getCommitOptions(): CommitOptions
	{
		return new CommitOptions([
			'moduleId' => $this->getModuleId(),
			'savePath' => self::SAVE_PATH,
		]);
	}

	private function hasRights(): bool
	{
		// the current user, the task and the field are fixed for the instance lifetime
		$this->hasRights ??= $this->checkRights();

		return $this->hasRights;
	}

	/**
	 * The rule of who may upload for a file field of a task is not repeated here: it is
	 * TaskFileUploaderContext plus its isCurrentUserPerformer(). This controller is the entry that comes
	 * in by the code of the field, the card of the task - the entry by the name of the form control.
	 */
	private function checkRights(): bool
	{
		$context = TaskFileUploaderContext::createFromTaskByFieldName(
			(int)$this->getOption(self::OPTION_TASK_ID),
			(string)$this->getOption(self::OPTION_FIELD_ID)
		);

		return $context?->isCurrentUserPerformer() ?? false;
	}
}
