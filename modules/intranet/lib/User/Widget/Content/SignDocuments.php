<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet;
use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Main\Localization\Loc;

class SignDocuments extends BaseContent
{
	public function getName(): string
	{
		return 'signDocuments';
	}

	public function getConfiguration(): array
	{
		$isAvailable = self::isSignDocumentAvailable();

		if (!$isAvailable)
		{
			return [
				'isAvailable' => false,
			];
		}

		return [
			'isAvailable' => true,
			'isLocked' => $this->isSignDocumentLocked(),
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_SIGN_DOCUMENTS_TITLE'),
			'description' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_SIGN_DOCUMENTS_DESCRIPTION'),
			'counter' => self::getCount(),
			'counterEventName' => self::getCounterEventName(),
		];
	}

	public static function getCount(): int
	{
		return Intranet\Internal\Integration\Sign\Documents::getCount();
	}

	public static function getCounterEventName(): string
	{
		return Intranet\Internal\Integration\Sign\Documents::getPullCounterEventName();
	}

	public static function isSignDocumentAvailable(): bool
	{
		return Intranet\Internal\Integration\Sign\Documents::isAvailable();
	}

	private function isSignDocumentLocked(): bool
	{
		return Intranet\Internal\Integration\Sign\Documents::isLocked();
	}
}
