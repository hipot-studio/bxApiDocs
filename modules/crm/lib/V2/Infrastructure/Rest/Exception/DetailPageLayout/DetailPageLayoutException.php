<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\DetailPageLayout;

use Bitrix\Rest\V3\Exception\RestException;

abstract class DetailPageLayoutException extends RestException
{
	public function __construct(
		private readonly string $registryCode,
		private readonly string $publicMessage,
		string $status,
	)
	{
		parent::__construct(status: $status);
	}

	public function getRegistryCode(): string
	{
		return $this->registryCode;
	}

	protected function getMessagePhraseCode(): string
	{
		return '';
	}

	protected function getLocalMessage(string $languageCode): string
	{
		return $this->publicMessage;
	}
}
