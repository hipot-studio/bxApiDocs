<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Support;

use Bitrix\Main\Error;

trait ApplicationErrors
{
	private function getLastApplicationError(): ?Error
	{
		global $APPLICATION;

		$exception = $APPLICATION->GetException();
		if (!$exception)
		{
			return null;
		}

		$error = new Error(
			(string)$exception->GetString(),
			(string)$exception->GetID(),
		);
		$APPLICATION->ResetException();

		return $error;
	}

	private function getLastApplicationErrorText(): ?string
	{
		return $this->getLastApplicationError()?->getMessage();
	}
}
