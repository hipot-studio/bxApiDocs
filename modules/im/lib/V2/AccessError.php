<?php
declare(strict_types=1);

namespace Bitrix\Im\V2;

class AccessError extends Error
{
	public function __construct(
		string $code,
		public readonly bool $revokeAccess = true,
		...$args,
	)
	{
		parent::__construct($code, ...$args);
	}

	public static function fromError(\Bitrix\Main\Error $original, bool $revokeAccess): self
	{
		return new self(
			(string)$original->getCode(),
			$revokeAccess,
			$original->getMessage(),
			$original->getCustomData() ?? [],
		);
	}
}
