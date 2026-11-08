<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Application\AppInstallRequest;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

abstract class AppInstallRequestException extends SystemException
{
	protected const CODE = '';

	public function __construct()
	{
		Loc::loadLanguageFile((new \ReflectionClass(static::class))->getFileName());

		parent::__construct((string)Loc::getMessage($this->getMessagePhrase()));
	}

	abstract protected function getMessagePhrase(): string;

	public function getErrorCode(): string
	{
		return static::CODE;
	}
}
