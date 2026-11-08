<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

/** A transport value; authority comes from verification of its server signature. */
final readonly class TrustedSubmissionContext
{
	public function __construct(public int $formId, public string $presentationToken)
	{
		if ($formId <= 0 || $formId > 2147483647 || $presentationToken === ''
			|| strlen($presentationToken) > 65536)
		{
			throw new \InvalidArgumentException('INVALID_PAYLOAD');
		}
	}
}
