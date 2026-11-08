<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\FragmentLibrary;

use Bitrix\Bizproc\Api\Data\TemplateFragmentService\Fragment;
use JsonSerializable;

/**
 * An item of the library as the client reads it (DTO-01). Every field is the field of the reading model of
 * the service, mapped one to one: the verdicts canEdit and canDelete are counted by the server, and the
 * visibility is the effective one, so a client never recomputes the rights of a row it was given.
 *
 * The envelope of a fragment has no field here and never travels this way: the only way out for the
 * content of a fragment is the prepared insertion.
 */
final class FragmentListItemDto implements JsonSerializable
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

	public static function fromFragment(Fragment $fragment): self
	{
		return new self(
			id: $fragment->id,
			name: $fragment->name,
			isShared: $fragment->isShared,
			authorId: $fragment->authorId,
			nodeCount: $fragment->nodeCount,
			canEdit: $fragment->canEdit,
			canDelete: $fragment->canDelete,
			createdAt: $fragment->createdAt,
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'isShared' => $this->isShared,
			'authorId' => $this->authorId,
			'nodeCount' => $this->nodeCount,
			'canEdit' => $this->canEdit,
			'canDelete' => $this->canDelete,
			'createdAt' => $this->createdAt,
		];
	}
}
