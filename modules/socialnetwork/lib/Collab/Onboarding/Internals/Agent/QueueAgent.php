<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Onboarding\Internals\Agent;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Socialnetwork\Collab\Onboarding\Command\Type\DeleteJobsCommand;
use Bitrix\Socialnetwork\Collab\Onboarding\Entity\JobCollection;
use Bitrix\Socialnetwork\Collab\Onboarding\Execution\Executor\BatchJobExecutor;
use Bitrix\Socialnetwork\Collab\Onboarding\OnboardingFeature;
use Bitrix\Socialnetwork\Collab\Onboarding\Provider\QueueProviderInterface;
use Bitrix\Socialnetwork\Collab\Onboarding\Service\AbstractQueueService;
use Bitrix\Socialnetwork\Collab\Onboarding\Internals\Repository\Cache\JobCacheProxy;
use Bitrix\Socialnetwork\Log\Logger;
use Bitrix\Socialnetwork\V2\Feature;

final class QueueAgent
{
	private BatchJobExecutor $batchJobExecutor;
	private AbstractQueueService $queueService;
	private QueueProviderInterface $queueProvider;

	private function __construct()
	{
		$this->queueService = ServiceLocator::getInstance()->get('socialnetwork.onboarding.queue.service');
		$this->queueProvider = ServiceLocator::getInstance()->get('socialnetwork.onboarding.queue.provider');

		$this->batchJobExecutor = ServiceLocator::getInstance()->get('socialnetwork.onboarding.batch.job.executor');
	}

	public static function execute(): string
	{
		if (Feature::isNewProjectsOn())
		{
			(new self())->clearQueue();

			return self::getAgentName();
		}

		if (!OnboardingFeature::isAvailable())
		{
			return self::getAgentName();
		}

		return (new self())->run();
	}

	private function clearQueue(): void
	{
		$jobs = $this->queueProvider->getAllIncludingProcessing();

		if ($jobs->isEmpty())
		{
			return;
		}

		$userIds = $jobs->getUserIdList();

		$result = (new DeleteJobsCommand(filter: ['USER_IDS' => $userIds]))->run();

		if (!$result->isSuccess())
		{
			Logger::log($result->getErrorCollection(), 'SOCIALNETWORK_COLLAB_ONBOARDING_DELETE_JOBS');
		}
	}

	private function run(): string
	{
		$jobs = $this->queueProvider->getAll();

		$this->processImmediately($jobs->getImmediatelyExecuted());
		$this->processNotImmediately($jobs->getNotImmediatelyExecuted());

		return self::getAgentName();
	}

	private function processImmediately(JobCollection $jobs): void
	{
		$this->processJobs($jobs, true);
	}

	private function processNotImmediately(JobCollection $jobs): void
	{
		$this->processJobs($jobs, false);
	}

	private function processJobs(JobCollection $jobs, bool $immediate): void
	{
		if ($jobs->isEmpty())
		{
			return;
		}

		$jobIds = $jobs->getIdList();
		$this->queueService->markAsProcessing(...$jobIds);

		$result = $this->batchJobExecutor->execute($jobs);
		$completedJobs = $result->getCompletedJobs();
		$notCompletedJobs = $result->getNotCompletedJobs();

		$this->queueService->deleteByJobIds(...$completedJobs->getIdList());
		$this->queueService->unmarkAsProcessing(...$notCompletedJobs->getIdList());

		if (!$immediate)
		{
			$cache = JobCacheProxy::getInstance();

			try
			{
				$cache->cleanByJobCollection($completedJobs->sortByUserId());
				$cache->save($notCompletedJobs->sortByUserId());
			}
			catch (\Throwable $t)
			{
				Logger::log($t, 'SOCIALNETWORK_COLLAB_ONBOARDING_CACHE');
			}
		}
	}

	public static function getAgentName(): string
	{
		return '\\' . self::class . '::execute();';
	}
}
