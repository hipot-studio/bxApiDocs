<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

/**
 * Readable form of one expression value, produced by
 * {@see \Bitrix\Bizproc\Activity\ReadableExpressionResolver}.
 *
 * The flag is counted while the references are being expanded, so a consumer tells "everything
 * expanded" apart without parsing the text back. Display-only; never persisted, never transported.
 */
final class ReadableExpressionResult
{
	public function __construct(
		public readonly string $text,
		public readonly bool $isFullyResolved,
	) {}
}
