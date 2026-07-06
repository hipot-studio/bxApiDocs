<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Exception;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

class MarketSubscriptionRequiredException extends AccessDeniedException
{
	protected const STATUS = \CRestServer::STATUS_PAYMENT_REQUIRED;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_MARKET_SUBSCRIPTION_REQUIRED_EXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		$copilotNameService = ServiceLocator::getInstance()->get(CopilotNameService::class);

		return [
			'#COPILOT_NAME#' => $copilotNameService->getCopilotName(),
		];
	}
}
