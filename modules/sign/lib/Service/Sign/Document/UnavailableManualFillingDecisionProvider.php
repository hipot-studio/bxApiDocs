<?php

namespace Bitrix\Sign\Service\Sign\Document;

use Bitrix\Main\Error;
use Bitrix\Sign\Contract\Document\ManualFillingDecisionProvider;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Result\Service\Sign\Document\ManualFillingDecisionResult;
use Bitrix\Sign\Type\Document\ManualFillingDecision;

final class UnavailableManualFillingDecisionProvider implements ManualFillingDecisionProvider
{
	public const ERROR_CODE = 'MANUAL_FILLING_PROVIDER_UNAVAILABLE';

	public function isReady(): bool
	{
		return false;
	}

	public function decide(Document $document): ManualFillingDecisionResult
	{
		$result = new ManualFillingDecisionResult(ManualFillingDecision::ERROR);
		$result->addError(new Error('Manual filling provider is unavailable', self::ERROR_CODE));

		return $result;
	}
}
