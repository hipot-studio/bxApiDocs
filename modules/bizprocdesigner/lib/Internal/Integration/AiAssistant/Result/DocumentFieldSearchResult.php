<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\DocumentFieldSearchMode;

/**
 * The outcome of one read of a document type's fields: what was answered, how it was chosen, and - when it
 * was not answered - which of the two things failed.
 *
 * The reason this exists is that the read used to answer three different situations with the same empty
 * collection: nothing matched the phrase, the search service replied without success, and the call to it
 * threw. An agent given an empty list cannot tell "this type has no such field" from "the search is down",
 * and it answers the two differently - it rewords the phrase for the first and retries later for the second.
 */
final readonly class DocumentFieldSearchResult
{
	private function __construct(
		public ?DocumentFieldCollection $fields,
		public DocumentFieldSearchMode $mode,
		public bool $isSearchUnavailable = false,
		public bool $areFieldsUnavailable = false,
	) {}

	/** An answer, possibly an empty one - which here means the set is genuinely empty. */
	public static function answered(DocumentFieldCollection $fields, DocumentFieldSearchMode $mode): self
	{
		return new self($fields, $mode);
	}

	/** The configured semantic search did not deliver: it replied without success, or the call threw. */
	public static function searchUnavailable(): self
	{
		return new self(null, DocumentFieldSearchMode::Semantic, isSearchUnavailable: true);
	}

	/** The field set of the type could not be read at all, which is about the portal and not about the phrase. */
	public static function fieldsUnavailable(DocumentFieldSearchMode $mode): self
	{
		return new self(null, $mode, areFieldsUnavailable: true);
	}

	public function isSuccess(): bool
	{
		return $this->fields !== null;
	}
}
