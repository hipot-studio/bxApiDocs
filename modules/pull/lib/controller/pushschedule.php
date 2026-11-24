<?php

declare(strict_types=1);

namespace Bitrix\Pull\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Pull\Service\PushSchedule\PolicyService;
use Bitrix\Pull\Service\PushSchedule\VacationClaimService;

final class PushSchedule extends JsonController
{
	public function configureActions(): array
	{
		return [
			'get' => $this->getActionConfiguration(),
			'save' => $this->getActionConfiguration(),
			'claimVacationPopup' => $this->getActionConfiguration(),
			'confirmVacationPopup' => $this->getActionConfiguration(),
		];
	}

	private function getActionConfiguration(): array
	{
		return [
			'-prefilters' => [Authentication::class],
			'+prefilters' => $this->getRequiredFilters(),
		];
	}

	private function getRequiredFilters(): array
	{
		return [
			new PushScheduleAuthentication(),
			new Csrf(),
			new HttpMethod([HttpMethod::METHOD_POST]),
		];
	}

	public function getAction(PolicyService $service): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			$this->addError(new Error('Authentication is required.', 'AUTH_REQUIRED'));

			return null;
		}

		$result = $service->get($userId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData()['policy'];
	}

	public function saveAction(
		int $revision,
		bool $automaticEnabled,
		PolicyService $service,
		array $rules = [],
		array $exceptionSourceIds = [],
	): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			$this->addError(new Error('Authentication is required.', 'AUTH_REQUIRED'));

			return null;
		}

		$result = $service->save($userId, $revision, $automaticEnabled, $rules, $exceptionSourceIds);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData()['policy'];
	}

	public function claimVacationPopupAction(
		VacationClaimService $service,
		int $protocolVersion = VacationClaimService::PROTOCOL_LEGACY,
	): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			$this->addError(new Error('Authentication is required.', 'AUTH_REQUIRED'));

			return null;
		}

		$result = $service->claim($userId, $protocolVersion);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData()['claim'];
	}

	public function confirmVacationPopupAction(int $absenceId, VacationClaimService $service): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			$this->addError(new Error('Authentication is required.', 'AUTH_REQUIRED'));

			return null;
		}

		$result = $service->confirm($userId, $absenceId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}

final class PushScheduleAuthentication extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		$userId = (int)$this->getAction()->getController()->getCurrentUser()?->getId();
		if ($userId > 0)
		{
			return null;
		}

		Context::getCurrent()->getResponse()->setStatus(401);
		$this->addError(new Error('Authentication is required.', 'AUTH_REQUIRED'));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
