<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateNoteService;

/**
 * A point of the canvas. A note carries two of them: the offset from its anchor, which takes the label
 * along with a moved node, and the absolute point, which leaves it where it was when the node is gone.
 */
final class Coordinates
{
	public function __construct(
		public readonly int $x,
		public readonly int $y,
	)
	{}
}
