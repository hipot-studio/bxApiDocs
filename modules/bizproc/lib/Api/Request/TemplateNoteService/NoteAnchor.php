<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateNoteService;

/**
 * The address of the note on the scheme, written once and never rewritten: a node keeps its own id, a
 * link keeps the normalized quadruple of its ends, a free point keeps neither. The three named
 * constructors are the only way in, so an anchor carrying the address of another kind cannot be built.
 */
final class NoteAnchor
{
	private function __construct(
		public readonly AnchorType $type,
		public readonly ?string $nodeId = null,
		public readonly ?string $sourceBlockId = null,
		public readonly ?string $sourcePortId = null,
		public readonly ?string $targetBlockId = null,
		public readonly ?string $targetPortId = null,
	)
	{}

	public static function node(string $nodeId): self
	{
		return new self(AnchorType::Node, nodeId: $nodeId);
	}

	public static function connection(
		string $sourceBlockId,
		string $sourcePortId,
		string $targetBlockId,
		string $targetPortId,
	): self
	{
		return new self(
			AnchorType::Connection,
			sourceBlockId: $sourceBlockId,
			sourcePortId: $sourcePortId,
			targetBlockId: $targetBlockId,
			targetPortId: $targetPortId,
		);
	}

	public static function free(): self
	{
		return new self(AnchorType::Free);
	}
}
