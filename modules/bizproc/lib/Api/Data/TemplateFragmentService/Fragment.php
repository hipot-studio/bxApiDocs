<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Data\TemplateFragmentService;

/**
 * A fragment as the library talks about it: what it is called, who saved it, how many nodes are in it and
 * what the current user may do with it. The verdicts are decided by the server and travel with the
 * fragment, so that no client recomputes the rights of a row it was given (DTO-01).
 *
 * The envelope is not here and never leaves the service on this path: the only way out for the content of
 * a fragment is the prepared insertion.
 */
final class Fragment
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly bool $isShared,
		public readonly int $authorId,
		public readonly int $nodeCount,
		public readonly bool $canEdit,
		public readonly bool $canDelete,
		public readonly int $createdAt,
	)
	{}
}
