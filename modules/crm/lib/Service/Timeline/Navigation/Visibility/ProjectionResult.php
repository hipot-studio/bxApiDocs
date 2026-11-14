<?php

namespace Bitrix\Crm\Service\Timeline\Navigation\Visibility;

final class ProjectionResult
{
	public const FULL = 'full';
	public const REDACTED = 'redacted';
	public const PLACEHOLDER = 'placeholder';
	public const HIDDEN = 'hidden';

	public function __construct(
		public readonly string $disposition,
		public readonly ?string $semanticKey,
		public readonly bool $isTechnical,
		public readonly array $safeDescriptor,
		public readonly mixed $source = null,
	)
	{
	}

	public function isVisible(): bool
	{
		return $this->disposition !== self::HIDDEN;
	}
}
