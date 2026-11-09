<?php

declare(strict_types=1);

namespace Bitrix\BizprocMobile\Trait;

use Bitrix\Main\Error;
use Bitrix\Mobile\Trait\PublicErrorsTrait;

trait AddPublicErrorsTrait
{
	use PublicErrorsTrait;

	/**
	 * An error dropped by the public marking (an empty message) is added as is, without the mark:
	 * a lost error would turn a failed action into a successful response or shift the error order.
	 *
	 * @param Error[] $errors
	 */
	protected function addErrorsAsPublic(array $errors): void
	{
		$publicErrors = [];

		foreach ($errors as $error)
		{
			$markedError = $this->markErrorsAsPublic([$error]);
			$publicErrors[] = $markedError[0] ?? $error;
		}

		$this->addErrors($publicErrors);
	}
}
