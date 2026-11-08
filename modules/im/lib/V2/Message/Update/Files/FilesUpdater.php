<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Update\Files;

use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Result;

/**
 * Owns the file composition scenario of a message update: the steps run in a fixed order around Message::save(),
 * and a step called out of turn fails without touching anything. A step opens the next one only once it has done
 * its work, so a failure never lets the scenario reach the irreversible fate of the dropped files.
 */
class FilesUpdater
{
	/**
	 * The wire form of an emptied composition: a form carries no empty list, and this one is unmistakably
	 * deliberate - nothing an uninitialized field of a client turns into.
	 */
	public const EMPTY_COMPOSITION = '[]';

	private const STEP_APPLY_BEFORE_SAVE = 1;
	private const STEP_SYNC_LINKS = 2;
	private const STEP_FINALIZE = 3;
	private const STEP_FINISHED = 4;

	private Message $message;
	private AddedFilesPreparer $addedFilesPreparer;
	private FilesValidationService $validationService;
	private FileLinksSynchronizer $linksSynchronizer;
	private DroppedFilesService $droppedFilesService;

	/** @var int[] */
	private array $requestedFileIds = [];

	/** @var int[] */
	private array $previousFileIds = [];

	private mixed $requestedComposition;

	private int $expectedStep = self::STEP_APPLY_BEFORE_SAVE;

	private function __construct(Message $message, mixed $requestedComposition)
	{
		$this->message = $message;
		$this->requestedComposition = $requestedComposition;
		$this->addedFilesPreparer = new AddedFilesPreparer();
		$this->validationService = new FilesValidationService();
		$this->linksSynchronizer = new FileLinksSynchronizer();
		$this->droppedFilesService = new DroppedFilesService();
	}

	/**
	 * Returns null when the input carries no composition at all: such an update does not touch files. The value the
	 * client sent is taken as it is - what it names is decided by the first step, where a refusal can be reported.
	 */
	public static function createFromFields(Message $message, array $fields): ?self
	{
		if (!array_key_exists(Params::FILE_ID, $fields))
		{
			return null;
		}

		return new self($message, $fields[Params::FILE_ID]);
	}

	public function applyBeforeSave(): Result
	{
		$result = $this->checkExpectedStep(self::STEP_APPLY_BEFORE_SAVE);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$compositionResult = $this->readRequestedComposition();
		if (!$compositionResult->isSuccess())
		{
			return $compositionResult;
		}

		$this->requestedFileIds = $compositionResult->getResult();
		$this->previousFileIds = $this->getStoredFileIds();

		// the preparation copies files on Disk, so a composition rejected by its own form never reaches it
		$validationResult = $this->validationService->validateRequest(
			$this->message,
			$this->requestedFileIds,
			$this->previousFileIds,
		);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$this->requestedFileIds = $this->addedFilesPreparer->prepare(
			$this->message,
			$this->requestedFileIds,
			$this->previousFileIds,
		);

		$validationResult = $this->validationService->validatePrepared(
			$this->message,
			$this->requestedFileIds,
			$this->addedFilesPreparer->getUnpreparedFileIds(),
		);
		if (!$validationResult->isSuccess())
		{
			$this->rollbackPreparedFiles();

			return $validationResult;
		}

		$this->applyComposition($validationResult->getResult());
		$this->applyRequestedOrder();

		$this->expectedStep = self::STEP_SYNC_LINKS;

		return $result;
	}

	public function syncLinks(): Result
	{
		$result = $this->checkExpectedStep(self::STEP_SYNC_LINKS);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$syncResult = $this->linksSynchronizer->sync($this->message, $this->requestedFileIds);
		if (!$syncResult->isSuccess())
		{
			return $syncResult;
		}

		$this->expectedStep = self::STEP_FINALIZE;

		return $syncResult;
	}

	/**
	 * The composition the links of the message were brought to by syncLinks(): the generic delta
	 * synchronization of an update reads it as its own "before", or it would link a second time what this
	 * scenario has linked already - the links storage takes a repeated pair without a word.
	 *
	 * @return int[]
	 */
	public function getSynchronizedFileIds(): array
	{
		return $this->requestedFileIds;
	}

	public function finalize(): Result
	{
		$result = $this->checkExpectedStep(self::STEP_FINALIZE);
		if (!$result->isSuccess())
		{
			return $result;
		}

		// the fate of the dropped files is the irreversible step and belongs after the verification, never before it
		$verificationResult = $this->verifyStoredComposition();
		if (!$verificationResult->isSuccess())
		{
			return $verificationResult;
		}

		$droppedFileIds = array_values(array_diff($this->previousFileIds, $this->requestedFileIds));
		$this->droppedFilesService->handle($this->message, $droppedFileIds);

		$this->expectedStep = self::STEP_FINISHED;

		return $result;
	}

	/**
	 * Removes the copies the preparation made for an edit that was not saved. Not a step of the scenario: the order is
	 * not involved and a call with nothing to remove does nothing.
	 */
	public function rollbackPreparedFiles(): void
	{
		$this->addedFilesPreparer->rollback();
	}

	/**
	 * Saving the params reports success even when the insert of the composition rows was rejected: within one save the
	 * result of the insert is overwritten by the result of the update. Set equality is not the criterion, because
	 * uploadFileFromText() legitimately widens the composition by a copy of the file linked in the text of the same
	 * edit, so two guarantees are checked instead: every requested file is stored, no dropped file survived.
	 */
	private function verifyStoredComposition(): Result
	{
		$result = new Result();

		$storedFileIds = $this->getStoredFileIds();
		$notStoredFileIds = array_diff($this->requestedFileIds, $storedFileIds);
		$droppedFileIds = array_diff($this->previousFileIds, $this->requestedFileIds);
		$notDroppedFileIds = array_intersect($droppedFileIds, $storedFileIds);

		if (!empty($notStoredFileIds) || !empty($notDroppedFileIds))
		{
			return $result->addError(new MessageError(MessageError::FILES_MISMATCH));
		}

		return $result;
	}

	/**
	 * The message takes the composition together with the models the validation has already read, so the steps that
	 * follow and the event of the edit work on those very models. Files leaving the composition stay out of the shared
	 * cache on purpose: the scenario removes some of them from Disk further down.
	 */
	private function applyComposition(FileCollection $files): void
	{
		FileCollection::addDiskFilesToPreload($files->getDiskFiles());
		$this->message->setFiles($files);
		$this->dropEmptiedComposition();
	}

	/**
	 * An empty composition reaches the params as a drop of the container and of nothing inside it, so a file linked in
	 * the text of the same edit revives it with the previous rows still there. The rows are dropped one by one instead.
	 */
	private function dropEmptiedComposition(): void
	{
		if (!empty($this->requestedFileIds))
		{
			return;
		}

		$this->message->getParams()->get(Params::FILE_ID)->unsetValue();
	}

	/**
	 * A composition shown in its natural order needs no parameter of the order. An empty value here also drops the row
	 * a previous edit left behind: kept as it is, the outdated order would be applied to the new composition.
	 */
	private function applyRequestedOrder(): void
	{
		$isNaturalOrder = $this->requestedFileIds === $this->getNaturalOrder();

		$this->message
			->getParams()
			->get(Params::FILE_ORDER)
			->setValue($isNaturalOrder ? [] : $this->requestedFileIds)
		;
	}

	/**
	 * Order the composition takes once it is stored: rows of the files that stay keep their place and the added ones
	 * land after them, so a plain reorder never changes it. Predicted, not read back: before the save the object of
	 * the params still answers with the previous composition.
	 *
	 * @return int[]
	 */
	private function getNaturalOrder(): array
	{
		$keptFileIds = array_intersect($this->previousFileIds, $this->requestedFileIds);
		$addedFileIds = array_diff($this->requestedFileIds, $this->previousFileIds);

		return array_values(array_merge($keptFileIds, $addedFileIds));
	}

	private function checkExpectedStep(int $step): Result
	{
		$result = new Result();

		if ($this->expectedStep !== $step)
		{
			return $result->addError(new FileError(FileError::UPDATE_STEP_OUT_OF_ORDER));
		}

		return $result;
	}

	/**
	 * Turns the value of the client into the composition of the message - the only place where that happens, and an
	 * empty composition of a message without text takes the message away. So a value that names no list of files is
	 * refused, never read as "take every attachment away": saying that stays the business of an empty list or of the
	 * spelled-out list that carries it over http. One unusable element refuses the whole value too, because the
	 * composition is a full replacement and dropping the element quietly would take away an attachment nobody asked
	 * to remove.
	 *
	 * @return Result<int[]>
	 */
	private function readRequestedComposition(): Result
	{
		$result = new Result();

		// an emptied composition reaches the server as a list spelled out: a form carries no empty list, so a
		// client that sent one would lose the field itself, and a request without the field touches no files.
		// The empty string is not that form on purpose - it is what a broken client sends by accident, and read
		// as "take every attachment away" it would delete a message that has no text left
		if ($this->requestedComposition === self::EMPTY_COMPOSITION)
		{
			return $result->setResult([]);
		}

		if (!is_array($this->requestedComposition))
		{
			return $result->addError(new FileError(FileError::WRONG_COMPOSITION));
		}

		$fileIds = [];
		foreach ($this->requestedComposition as $fileId)
		{
			if (!self::isFileId($fileId))
			{
				return $result->addError(new FileError(FileError::WRONG_COMPOSITION));
			}

			$fileIds[] = (int)$fileId;
		}

		return $result->setResult(array_values(array_unique($fileIds)));
	}

	/**
	 * An identifier arrives as a string through rest and as an integer from the callers inside, and both name the same
	 * file. Anything else names none: a fraction, a negative, a zero, an empty string, a word, a nested list.
	 */
	private static function isFileId(mixed $fileId): bool
	{
		if (is_string($fileId))
		{
			return preg_match('/^[0-9]+$/D', $fileId) === 1 && (int)$fileId > 0;
		}

		return is_int($fileId) && $fileId > 0;
	}

	/**
	 * Composition of the message as it is stored now, without files of a structured block. Read leniently on purpose:
	 * the rows have writers of their own, older than this scenario, and a row nobody can explain must not stand in the
	 * way of an edit.
	 *
	 * @return int[]
	 */
	private function getStoredFileIds(): array
	{
		$storedFileIds = (array)$this->message->getParams()->get(Params::FILE_ID)->getValue();
		$numeric = array_filter($storedFileIds, 'is_numeric');
		$positive = array_filter(array_map('intval', $numeric), static fn(int $fileId): bool => $fileId > 0);

		return array_values(array_unique($positive));
	}
}
