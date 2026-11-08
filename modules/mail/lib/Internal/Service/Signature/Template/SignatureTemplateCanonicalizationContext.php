<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template;

final class SignatureTemplateCanonicalizationContext
{
	public function __construct(
		public readonly int $userId,
		public readonly ?int $signatureId = null,
	)
	{
	}
}
