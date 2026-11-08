<?php

namespace Bitrix\Sign\Service\Integration\Bizproc\Result;

use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Result;

/**
 * Outcome of the draft creation flow of the activity.
 *
 * The shape is the same for a document created right now and for a document taken by the
 * idempotency key: only isReused tells them apart, so the activity fills its return properties with
 * one and the same code in both cases.
 *
 * The status is the internal status of the module and not the code of the REST dictionary: that
 * dictionary merges `new`, `uploaded` and `ready` into one value, and those are exactly the draft
 * stage and the window of the idempotency.
 *
 * Warnings are a channel of their own. A warning reaches the log of the process but never turns a
 * created document into a failure.
 */
final class DraftCreationResult extends Result
{
	private ErrorCollection $warnings;

	public function __construct(
		public readonly ?int $documentId = null,
		public readonly ?int $smartDocumentId = null,
		public readonly ?string $documentStatus = null,
		public readonly bool $isReused = false,
	)
	{
		parent::__construct();

		$this->warnings = new ErrorCollection();
	}

	public function addWarning(Error $warning): static
	{
		$this->warnings->setError($warning);

		return $this;
	}

	/**
	 * @return array<Error>
	 */
	public function getWarnings(): array
	{
		return $this->warnings->toArray();
	}
}
