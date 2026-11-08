<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateFragmentService;

/**
 * Only the name and the visibility of a fragment are ever changed; its content is not. A field left null
 * stays as it is, so a dialog that shows one of them sends one of them.
 */
final class UpdateFragmentRequest
{
	public function __construct(
		public readonly int $fragmentId,
		public readonly int $userId,
		public readonly ?string $name = null,
		public readonly ?bool $isShared = null,
	)
	{}
}
