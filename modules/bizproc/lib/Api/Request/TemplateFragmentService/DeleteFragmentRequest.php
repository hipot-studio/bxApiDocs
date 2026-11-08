<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Request\TemplateFragmentService;

final class DeleteFragmentRequest
{
	public function __construct(
		public readonly int $fragmentId,
		public readonly int $userId,
	)
	{}
}
