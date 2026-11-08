<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Response;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\DocumentTypeDto;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\DocumentFieldSearchMode;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * Response of bizprocdesigner.document.field.list: the fields, plus what they are the fields of and how
 * they were chosen (API-03).
 *
 * A listing that answers with two more things than a listing normally does, so it extends the crud one
 * rather than replacing it: `items` keeps its envelope and its select, and the two additions sit beside it
 * as the header of the answer.
 *
 * Both additions exist because the request may leave the answer ambiguous. The type may be the caller's or
 * the bound template's, and an agent holding several conversations cannot tell whose schema it just got. An
 * empty `items` may mean the phrase matched nothing or the type has no fields, and the mode is what separates
 * the two. Neither is narrowed by the select: they describe the answer, they are not part of the resource.
 */
final class DocumentFieldListResponse extends ListResponse
{
	public string $searchMode;

	public function __construct(
		DtoCollection $items,
		public DocumentTypeDto $documentType,
		DocumentFieldSearchMode $searchMode,
	) {
		parent::__construct($items);

		$this->searchMode = $searchMode->value;
	}
}
