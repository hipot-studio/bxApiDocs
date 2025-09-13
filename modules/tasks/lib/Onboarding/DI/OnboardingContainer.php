<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\DI;

use Bitrix\Tasks\DI\AbstractContainer;
use Bitrix\Tasks\Onboarding\Command\CommandRepositoryInterface;
use Bitrix\Tasks\Onboarding\Command\Executor\BatchCommandExecutor;
use Bitrix\Tasks\Onboarding\Command\Repository\CommandRepository;
use Bitrix\Tasks\Onboarding\Counter\CounterRepositoryInterface;
use Bitrix\Tasks\Onboarding\Counter\CounterServiceInterface;
use Bitrix\Tasks\Onboarding\Counter\Repository\CounterRepository;
use Bitrix\Tasks\Onboarding\Counter\Service\CounterService;
use Bitrix\Tasks\Onboarding\Internal\Queue\QueueServiceInterface;
use Bitrix\Tasks\Onboarding\Internal\Queue\Service\QueueService;
use Bitrix\Tasks\Onboarding\Notification\NotificationController;
use Bitrix\Tasks\Onboarding\Promo\Repository\InviteToMobileRepositoryInterface;
use Bitrix\Tasks\Onboarding\Promo\Service\InviteToMobileService;
use Bitrix\Tasks\Onboarding\Task\Repository\TaskRepository;
use Bitrix\Tasks\Onboarding\Task\TaskRepositoryInterface;
use Bitrix\Tasks\Onboarding\View\Repository\ViewRepository;
use Bitrix\Tasks\Onboarding\View\ViewRepositoryInterface;

final class OnboardingContainer extends AbstractContainer
{
	public function getQueueService(): QueueServiceInterface
	{
		return $this->getRegisteredObject(QueueService::class);
	}

	public function getViewRepository(): ViewRepositoryInterface
	{
		return $this->getRegisteredObject(ViewRepository::class);
	}

	public function getCommandRepository(): CommandRepositoryInterface
	{
		return $this->getRegisteredObject(CommandRepository::class);
	}

	public function getBatchCommandExecutor(): BatchCommandExecutor
	{
		return $this->getRegisteredObject(BatchCommandExecutor::class);
	}

	public function getNotificationController(): NotificationController
	{
		return $this->getRuntimeObject(
			static fn (): NotificationController => new NotificationController(),
			'tasks.onboarding.notification.controller'
		);
	}

	public function getInviteToMobileService(): InviteToMobileService
	{
		return $this->getRegisteredObject(InviteToMobileService::class);
	}

	public function getInviteToMobileRepository(): InviteToMobileRepositoryInterface
	{
		return $this->getRuntimeObjectWithDi(InviteToMobileRepositoryInterface::class);
	}

	public function getCounterService(): CounterServiceInterface
	{
		return $this->getRegisteredObject(CounterService::class);
	}

	public function getCounterRepository(): CounterRepositoryInterface
	{
		return $this->getRegisteredObject(CounterRepository::class);
	}

	public function getTaskRepository(): TaskRepositoryInterface
	{
		return $this->getRegisteredObject(TaskRepository::class);
	}
}