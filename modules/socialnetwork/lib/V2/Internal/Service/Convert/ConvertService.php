<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Convert;

use Bitrix\Main\Error;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Provider\GroupProvider;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertProgress;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertResult;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertTrackedHandler;
use Bitrix\Socialnetwork\V2\Internal\Repository\ConvertProgressRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\Factory\HandlerFactoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\ConvertChat;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\EnsureChat;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\EnsureProjectOwner;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\SendConverterEvent;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\SendConverterPushEvent;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\SetProjectOptions;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\StoreFeatureStatesOnConvert;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateFeatures;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateGroupEventsChat;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateGroupFields;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateFeaturePermissions;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\SyncProjectChatMembers;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\UpdateGroupTasksChat;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\StatusResolve\StatusResolveService;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectAnalytics;
use Throwable;

class ConvertService
{
	public function __construct(
		private readonly HandlerFactoryInterface $handlerFactory,
		private readonly ConvertProgressRepositoryInterface $progressRepository,
		private readonly StatusResolveService $statusResolveService,
		private readonly ProjectAnalytics $projectAnalytics,
	)
	{

	}

	public function convert(int $groupId, int $userId): ConvertResult
	{
		$result = new ConvertResult();

		$groupBefore = $this->reload($groupId);
		if ($groupBefore === null)
		{
			return $result->addError(new Error('Group not found'));
		}

		// A row may hold no type at all, and then only the legacy markers tell a scrum from a project.
		// Without resolving them a legacy scrum slips past the guard below, and the status service - which
		// takes the type as a required argument - is handed nothing
		$typeBefore = $groupBefore->getType()
			?? GroupProvider::getInstance()->getGroupType($groupId)
			?? Workgroup\Type::getDefault()
		;

		if ($typeBefore === Workgroup\Type::Scrum)
		{
			return $result->addError(new Error('Scrum group cannot be converted'));
		}

		$progress = $this->progressRepository->getByGroupId($groupId);

		if ($this->statusResolveService->isForbiddenForStart($progress))
		{
			return $result->setGroupAfter($groupBefore);
		}

		// This durable exclusion precedes every progress save, including the completed status.
		$this->progressRepository->markNewConversion($groupId);
		$this->statusResolveService->start($progress, $typeBefore);
		$this->progressRepository->save($progress);

		$sourceType = $groupBefore->getType();
		$this->projectAnalytics->trackConvertStart(
			groupId: $groupId,
			sourceType: $sourceType,
			userId: $userId,
			isOpened: $groupBefore->isOpened(),
		);

		try
		{
			$this->handlerFactory->create(EnsureProjectOwner::class)($groupBefore);

			$this->handlerFactory->create(UpdateGroupFields::class)($groupBefore);

			$this->handlerFactory->create(StoreFeatureStatesOnConvert::class)($groupBefore);

			$this->handlerFactory->create(UpdateFeatures::class)($groupBefore);

			$this->handlerFactory->create(UpdateFeaturePermissions::class)($groupBefore);

			$this->handlerFactory->create(EnsureChat::class)($groupBefore);

			$this->handlerFactory->create(SetProjectOptions::class)($groupBefore, $progress);
		}
		catch (Throwable $t)
		{
			$this->statusResolveService->fail($progress);
			$this->projectAnalytics->trackConvertFinish(
				groupId: $groupId,
				sourceType: $sourceType,
				userId: $userId,
				errorCode: (string)$t->getCode(),
			);

			throw $t;
		}
		finally
		{
			$this->progressRepository->save($progress);
		}

		$groupAfter = $this->reload($groupId);

		if ($groupAfter === null)
		{
			$this->statusResolveService->fail($progress);
			$this->progressRepository->save($progress);
			$this->projectAnalytics->trackConvertFinish(
				groupId: $groupId,
				sourceType: $sourceType,
				userId: $userId,
				errorCode: 'group_not_found',
			);

			return $result->addError(new Error('Group not found after conversion'));
		}

		try
		{
			$this->executeWithMarkIfNotDone(
				$progress,
				ConvertTrackedHandler::ConvertChat,
				$groupBefore,
				$userId,
				$groupAfter,
			);

			$this->executeWithMarkIfNotDone(
				$progress,
				ConvertTrackedHandler::SyncProjectChatMembers,
				$groupAfter,
			);

			$this->executeWithMarkIfNotDone(
				$progress,
				ConvertTrackedHandler::UpdateGroupTasksChat,
				$groupAfter,
			);

			$this->executeWithMarkIfNotDone(
				$progress,
				ConvertTrackedHandler::UpdateGroupEventsChat,
				$groupAfter,
			);

			$this->executeWithMarkIfNotDone(
				$progress,
				ConvertTrackedHandler::SendConverterEvent,
				$groupBefore,
				$groupAfter,
			);

			$this->executeWithMarkIfNotDone(
				$progress,
				ConvertTrackedHandler::SendConverterPushEvent,
				$groupBefore,
				$groupAfter,
			);

			$this->statusResolveService->complete($progress);
		}
		catch (Throwable $t)
		{
			$this->statusResolveService->fail($progress);
			$this->projectAnalytics->trackConvertFinish(
				groupId: $groupId,
				sourceType: $sourceType,
				userId: $userId,
				isOpened: $groupAfter->isOpened(),
				errorCode: (string)$t->getCode(),
			);

			throw $t;
		}
		finally
		{
			$this->progressRepository->save($progress);
		}

		$this->projectAnalytics->trackConvertFinish(
			groupId: $groupId,
			sourceType: $sourceType,
			userId: $userId,
			isOpened: $groupAfter->isOpened(),
		);

		return $result->setGroupAfter($groupAfter);
	}

	private function reload(int $groupId): ?Workgroup
	{
		return GroupRegistry::getInstance()
			->invalidate($groupId)
			->get($groupId)
		;
	}

	private function executeWithMarkIfNotDone(ConvertProgress $progress, ConvertTrackedHandler $trackedHandler, ...$args): void
	{
		if ($progress->isHandlerExecuted($trackedHandler))
		{
			return;
		}

		$class = match($trackedHandler)
		{
			ConvertTrackedHandler::ConvertChat => ConvertChat::class,
			ConvertTrackedHandler::SyncProjectChatMembers => SyncProjectChatMembers::class,
			ConvertTrackedHandler::SendConverterEvent => SendConverterEvent::class,
			ConvertTrackedHandler::SendConverterPushEvent => SendConverterPushEvent::class,
			ConvertTrackedHandler::UpdateGroupTasksChat => UpdateGroupTasksChat::class,
			ConvertTrackedHandler::UpdateGroupEventsChat => UpdateGroupEventsChat::class,
		};

		$this->handlerFactory->create($class)(...$args);

		$progress->markHandlerExecuted($trackedHandler);
	}
}
