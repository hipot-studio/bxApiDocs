<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller\VibecodePort;

use Bitrix\Main\Engine\Response;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemoGateway;

class VibeDemo extends Base
{
	private VibeDemoGateway $gateway;

	public function __construct(?Request $request = null, ?VibeDemoGateway $gateway = null)
	{
		$this->gateway = $gateway ?? new VibeDemoGateway();
		parent::__construct($request);
	}

	public function getStateAction(): Response\AjaxJson
	{
		return $this->respond($this->gateway->getState());
	}

	public function activateAction(?int $days = null): Response\AjaxJson
	{
		return $this->respond($this->gateway->activate($days));
	}

	public function resetAction(): Response\AjaxJson
	{
		return $this->respond($this->gateway->reset());
	}

	private function respond(Result $result): Response\AjaxJson
	{
		return $result->isSuccess()
			? Response\AjaxJson::createSuccess($result->getData())
			: Response\AjaxJson::createError($result->getErrorCollection())
		;
	}
}
