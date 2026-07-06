<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main\Entity\EntityInterface;

class AppExternalAttribute extends AppAttribute implements EntityInterface
{
	public function __construct(
		?int $appId,
		string $code,
		?string $value = null,
	)
	{
		parent::__construct(
			$appId,
			$code,
			$value,
			self::TYPE_EXTERNAL
		);
	}
}
