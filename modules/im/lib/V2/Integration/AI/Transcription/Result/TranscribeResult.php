<?php

namespace Bitrix\Im\V2\Integration\AI\Transcription\Result;

use Bitrix\Im\V2\Integration\AI\Transcription\Item\TranscribeFileItem;
use Bitrix\Im\V2\Result;

class TranscribeResult extends Result
{
	protected TranscribeFileItem $fileItem;

	/**
	 * True only when this call actually queued a new AI transcription job,
	 * so a queue callback with the caller's context parameters will arrive later.
	 * False for short-circuit outcomes: transcription already completed, already
	 * pending from another flow, or a synchronous failure.
	 */
	protected bool $newJobQueued = false;

	public function __construct(TranscribeFileItem $fileItem)
	{
		parent::__construct();
		$this->fileItem = $fileItem;
	}

	public function setNewJobQueued(bool $newJobQueued): self
	{
		$this->newJobQueued = $newJobQueued;

		return $this;
	}

	public function isNewJobQueued(): bool
	{
		return $this->newJobQueued;
	}

	public function setFileItem(TranscribeFileItem $fileItem): self
	{
		$this->fileItem = $fileItem;

		return $this;
	}

	public function getFileItem(): TranscribeFileItem
	{
		return $this->fileItem;
	}
}
