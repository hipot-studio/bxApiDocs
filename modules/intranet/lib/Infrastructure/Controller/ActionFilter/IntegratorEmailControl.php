<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Bitrix24\Integrator;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class IntegratorEmailControl extends Base
{
	public function __construct(
		private readonly string $email,
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$error = new Error('');
		$result = Integrator::checkPartnerEmail($this->email, $error);

		if (!$result->isSuccess())
		{
			$this->addError($error);

			return new EventResult(EventResult::ERROR, null, 'bitrix24', $this);
		}

		$controller = $this->getAction()->getController();
		$controller->setSourceParametersList([
			...$controller->getSourceParametersList(),
			['partnerData' => $result->getData()],
		]);

		return null;
	}
}