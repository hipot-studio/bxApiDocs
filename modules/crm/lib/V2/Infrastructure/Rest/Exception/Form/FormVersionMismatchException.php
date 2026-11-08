<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Form;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

final class FormVersionMismatchException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_FORM_VERSION_MISMATCH';

	private const ENGLISH_MESSAGE = 'CRM form was changed after it was read.';

	protected const STATUS = CRestServer::STATUS_CONFLICT;

	private readonly DateTime $currentModifiedTime;

	public function __construct(DateTime $currentModifiedTime)
	{
		$this->currentModifiedTime = clone $currentModifiedTime;
		parent::__construct();
	}

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	public function output(?string $responseLanguage = null): array
	{
		$out = parent::output($responseLanguage);
		$out['currentUpdatedTime'] = $this->currentModifiedTime->format(DATE_ATOM);

		return $out;
	}

	protected function getLocalMessage(string $languageCode): string
	{
		return $languageCode === 'en'
			? self::ENGLISH_MESSAGE
			: parent::getLocalMessage($languageCode)
		;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CRM_V2_REST_FORM_VERSION_MISMATCH';
	}
}
