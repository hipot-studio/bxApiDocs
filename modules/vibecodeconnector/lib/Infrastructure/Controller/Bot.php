<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter\CheckIncomingJwt;
use Bitrix\Vibecodeconnector\Internal\Exception\BotOperationFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Bot\BotService;

/**
 * Vibecode pull endpoint for bot info.
 * Accessible only via the vibecodeconnector JWT (`CheckIncomingJwt`).
 */
final class Bot extends Controller
{
	protected function getDefaultPreFilters()
	{
		return [
			new HttpMethod([HttpMethod::METHOD_POST]),
			new CheckIncomingJwt(),
			new Csrf(false),
		];
	}

	public function infoAction(): Response\AjaxJson
	{
		$service = ServiceLocator::getInstance()->get(BotService::class);

		try
		{
			return Response\AjaxJson::createSuccess($service->getBotInfo());
		}
		catch (BotOperationFailedException $e)
		{
			$this->addError(new Error($e->getMessage(), (string)($e->getErrorCode() ?? 'BOT_INFO_FAILED')));

			return Response\AjaxJson::createError($this->errorCollection);
		}
	}
}
