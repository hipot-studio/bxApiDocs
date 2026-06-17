<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter\Access;

use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

class AccessDeniedEventResult extends EventResult
{
	private const ERROR_ACCESS_DENIED = 'access_denied';

	public function __construct(
		private readonly Error $accessError,
	)
	{
		parent::__construct(self::ERROR);
	}

	public static function create(
		?Error $error = null,
		?string $errorMessage = null,
	): self
	{
		$error ??= new Error(
			$errorMessage ?? Loc::getMessage('MAIN_ENGINE_FILTER_ACCESS_DENIED'),
			self::ERROR_ACCESS_DENIED,
		);

		return new self($error);
	}

	public function getAccessError(): Error
	{
		return $this->accessError;
	}
}
