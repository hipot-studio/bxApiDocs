<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateFragmentService;

/**
 * The nodes and the links come from the selection the client holds, not from the graph of the template:
 * saving a fragment reads no template and writes no template. The template is named only to ask whether
 * this person may cut a fragment out of it.
 */
final class CreateFragmentRequest
{
	/**
	 * @param int $userId the current user; the fragment is attributed to them, the author never comes from
	 * the payload
	 * @param array $documentType the triple of the document type of the source template
	 */
	public function __construct(
		public readonly int $userId,
		public readonly string $name,
		public readonly bool $isShared,
		public readonly int $templateId,
		public readonly array $documentType,
		public readonly array $blocks,
		public readonly array $connections,
	)
	{}
}
