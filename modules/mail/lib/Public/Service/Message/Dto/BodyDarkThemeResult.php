<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Dto;

/**
 * Body a consumer has to show, together with the answer why it is not the recolored one.
 *
 * The refusal is a normal outcome and not an error: the consumer always gets a body to show. A reason is set only
 * when the recolor was actually attempted and refused; a request that never asked for the dark theme - the feature
 * is off, the light scheme is requested, the body carries no markup - carries no reason.
 */
final class BodyDarkThemeResult
{
	public function __construct(
		public readonly string $body,
		public readonly bool $themed,
		public readonly ?string $reason = null,
	)
	{
	}
}
