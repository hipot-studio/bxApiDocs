<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Batch;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Service\CacheService;
use Bitrix\Tasks\V2\Internal\Service\DeleteTemplateService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\ScheduleProjectionService;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentSubject;
use Bitrix\Tasks\V2\Internal\Service\UpdateTemplateService;
use Throwable;

/**
 * Batch member / move / delete над шаблонами (API-S7, P4.T2).
 *
 * Тонкая обёртка над существующими per-item операциями: смена участников/постановщика
 * и перенос в проект — через {@see UpdateTemplateService::update()}; удаление — через
 * {@see DeleteTemplateService::delete()}. Логику per-item сервисов не дублирует — только
 * цикл + per-row права (AC-S-065) + агрегация результата.
 *
 * Изменения влияют только на будущие постановки; созданные экземпляры не трогаются
 * (AC-S-041). Удаление снимает шаблон и прекращает будущие постановки, экземпляры
 * остаются (AC-S-042). Раздел нового флоу создания не вводит (Q-5).
 */
class BatchTemplateActionService
{
	public const ACTION_CHANGE_RESPONSIBLE = 'changeResponsible';
	public const ACTION_CHANGE_ORIGINATOR = 'changeOriginator';
	public const ACTION_CHANGE_AUDITORS = 'changeAuditors';
	public const ACTION_CHANGE_ACCOMPLICES = 'changeAccomplices';
	public const ACTION_MOVE_TO_PROJECT = 'moveToProject';
	public const ACTION_DELETE = 'delete';

	public function __construct(
		private readonly UpdateTemplateService $updateTemplateService,
		private readonly DeleteTemplateService $deleteTemplateService,
		private readonly TemplateAccessService $templateAccessService,
		private readonly CacheService $cacheService,
	)
	{
	}

	/**
	 * @param int[] $templateIds
	 * @param array<string, mixed> $payload
	 * @return array<int, array{id:int, status:string, message?:string}>
	 */
	public function run(
		array $templateIds,
		string $action,
		array $payload,
		int $userId,
		?AuthorAssignmentSubject $authorSubject = null,
	): array
	{
		if (!$this->isSupported($action))
		{
			return array_map(
				static fn (int $id): array => [
					'id' => $id,
					'status' => 'error',
					'message' => (string)Loc::getMessage('TASKS_V2_REPLICATION_BATCH_UNSUPPORTED_ACTION'),
				],
				$this->normalizeIds($templateIds),
			);
		}

		$templateIds = $this->normalizeIds($templateIds);
		$allowed = [];
		foreach ($templateIds as $templateId)
		{
			$allowed[$templateId] = $this->hasRights($templateId, $action, $payload, $userId);
		}

		$results = [];
		foreach ($templateIds as $templateId)
		{
			$results[] = $allowed[$templateId]
				? $this->applyOne($templateId, $action, $payload, $userId, $authorSubject)
				: ['id' => $templateId, 'status' => 'denied'];
		}

		return $results;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{id:int, status:string, message?:string}
	 */
	private function applyOne(
		int $templateId,
		string $action,
		array $payload,
		int $userId,
		?AuthorAssignmentSubject $authorSubject,
	): array
	{
		try
		{
			if ($action === self::ACTION_DELETE)
			{
				$this->deleteTemplateService->delete($templateId, new DeleteConfig(userId: $userId));
			}
			else
			{
				$this->updateTemplateService->update(
					$this->buildUpdateEntity($templateId, $action, $payload),
					new UpdateConfig(userId: $userId, authorSubject: $authorSubject),
				);
			}

			$this->cacheService->clearByTagMulti(ScheduleProjectionService::CACHE_TAG_PREFIX, [$templateId]);

			return ['id' => $templateId, 'status' => 'ok'];
		}
		catch (Throwable $e)
		{
			LogFacade::logThrowable($e);

			return [
				'id' => $templateId,
				'status' => 'error',
				'message' => (string)Loc::getMessage('TASKS_V2_REPLICATION_BATCH_UPDATE_ERROR'),
			];
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function buildUpdateEntity(int $templateId, string $action, array $payload): Entity\Template
	{
		return Entity\Template::mapFromArray([
			'id' => $templateId,
			...$this->buildUpdateProps($action, $payload),
		]);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function buildUpdateProps(string $action, array $payload): array
	{
		return match ($action)
		{
			self::ACTION_CHANGE_RESPONSIBLE => [
				'responsibleCollection' => UserCollection::mapFromIds($this->userIds($payload)),
			],
			self::ACTION_CHANGE_ORIGINATOR => [
				'creator' => new Entity\User(id: (int)($payload['userId'] ?? 0)),
			],
			self::ACTION_CHANGE_AUDITORS => [
				'auditors' => UserCollection::mapFromIds($this->userIds($payload)),
			],
			self::ACTION_CHANGE_ACCOMPLICES => [
				'accomplices' => UserCollection::mapFromIds($this->userIds($payload)),
			],
			self::ACTION_MOVE_TO_PROJECT => [
				'group' => new Entity\Group(id: (int)($payload['groupId'] ?? 0)),
			],
			default => [],
		};
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function hasRights(int $templateId, string $action, array $payload, int $userId): bool
	{
		if ($action === self::ACTION_DELETE)
		{
			// Удаление требует прав владельца/администратора (AC-S-065).
			return $this->templateAccessService->canRemove($userId, $templateId);
		}

		return $this->templateAccessService->canSave(
			$userId,
			$this->buildUpdateEntity($templateId, $action, $payload),
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return int[]
	 */
	private function userIds(array $payload): array
	{
		$ids = $payload['userIds'] ?? ($payload['userId'] ?? []);
		if (!is_array($ids))
		{
			$ids = [$ids];
		}

		return array_values(array_filter(array_map('intval', $ids)));
	}

	private function isSupported(string $action): bool
	{
		return in_array($action, [
			self::ACTION_CHANGE_RESPONSIBLE,
			self::ACTION_CHANGE_ORIGINATOR,
			self::ACTION_CHANGE_AUDITORS,
			self::ACTION_CHANGE_ACCOMPLICES,
			self::ACTION_MOVE_TO_PROJECT,
			self::ACTION_DELETE,
		], true);
	}

	/**
	 * @param int[] $templateIds
	 * @return int[]
	 */
	private function normalizeIds(array $templateIds): array
	{
		return array_values(array_filter(array_unique(array_map('intval', $templateIds))));
	}
}
