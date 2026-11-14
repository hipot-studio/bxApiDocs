<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Disk;

final readonly class UploadQuotaStop
{
	public function __construct(
		private ?string $infoHelperCode,
	)
	{
	}

	public function getInfoHelperCode(): ?string
	{
		return $this->infoHelperCode;
	}
}
