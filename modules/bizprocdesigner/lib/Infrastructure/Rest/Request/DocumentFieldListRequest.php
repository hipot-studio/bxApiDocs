<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\ListRequest;

/**
 * Reading the fields of a document type: the one the bound template runs on, or another one it may name.
 *
 * A search phrase is not a predicate over the full set: it switches the read to the semantic search
 * service, which answers with the fields closest to the phrase. Hence a field of its own and not filter.
 */
final class DocumentFieldListRequest extends ListRequest
{
	public ?string $search = null;

	/**
	 * The type to read, written in full as `module@entity@documentType`. Omitted, the bound template's own
	 * type is read and the answer keeps the semantics it always had.
	 *
	 * Only the full spelling is accepted. The short one - the document type alone - is refused rather than
	 * resolved: nothing in the code base resolves an entity from it, so any resolution here would be a guess
	 * about which entity the caller meant, and the answer to that guess is a schema of another entity.
	 */
	public ?string $documentType = null;
}
