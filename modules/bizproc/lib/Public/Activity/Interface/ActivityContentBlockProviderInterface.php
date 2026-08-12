<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

use Bitrix\Bizproc\Activity\Dto\ContentBlock;
use Bitrix\Bizproc\Activity\Dto\ContentBlockContext;

/**
 * Implemented by activities that declare the display text shown inside the node's content block
 * on the designer canvas.
 *
 * Return null to render nothing. The implementation must be self-contained: resolve any
 * titles itself and apply its own empty-state placeholder text.
 */
interface ActivityContentBlockProviderInterface
{
	/**
	 * @param array $properties Activity Properties (after dialog-values resolution).
	 * @param ContentBlockContext|null $context Optional resolution context (carries the template-wide scope).
	 */
	public static function getContentBlock(array $properties, ?ContentBlockContext $context = null): ?ContentBlock;
}
