<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Batch;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\CacheService;
use Bitrix\Tasks\V2\Internal\Service\ReplicationService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\ScheduleProjectionService;
use Throwable;

/**
 * Batch пауза/возобновление повтора (ALG-S4, API-S6, P4.T1).
 *
 * Цикл по templateIds: per-row проверка прав, установка `REPLICATE = Y/N` через
 * {@see ReplicationService::setReplicationStateByTemplate()} (паттерн «рестарт
 * расписания»), инвалидация кэша проекции. «Приостановить» уводит правило из активных,
 * не трогая экземпляры; «Возобновить» смотрит только вперёд, без backfill (AC-S-040).
 * Пауза `after_completion` перекрывает событийный триггер — логика уже в Части I,
 * batch лишь выставляет `REPLICATE=N` (AC-S-074).
 *
 * Недоступные не меняются, возвращается понятный per-row результат (ERR-S-011),
 * без «тихого» пропуска.
 */
class BatchReplicateStateService
{
	public function __construct(
		private readonly ReplicationService $replicationService,
		private readonly TemplateAccessService $templateAccessService,
		private readonly CacheService $cacheService,
	)
	{
	}

	/**
	 * @param int[] $templateIds
	 * @return array<int, array{id:int, status:string, message?:string}>
	 */
	public function setState(array $templateIds, int $userId, bool $replicate): array
	{
		$templates = [];

		foreach ($this->normalizeIds($templateIds) as $templateId)
		{
			$templates[$templateId] = new Entity\Template(id: $templateId, replicate: $replicate);
		}
		$allowed = $this->templateAccessService->canSaveBatch($userId, array_values($templates));

		$results = [];
		foreach ($templates as $templateId => $template)
		{
			$results[] = ($allowed[$templateId] ?? false)
				? $this->applyOne($template, $userId)
				: ['id' => $templateId, 'status' => 'denied'];
		}

		return $results;
	}

	/**
	 * @return array{id:int, status:string, message?:string}
	 */
	private function applyOne(Entity\Template $template, int $userId): array
	{
		$templateId = $template->id;

		try
		{
			$this->replicationService->setReplicationStateByTemplate($template, $userId);

			// backfill НЕ выполняется; пауза after_completion перекрыта Частью I
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
	 * @param int[] $templateIds
	 * @return int[]
	 */
	private function normalizeIds(array $templateIds): array
	{
		return array_values(array_filter(array_unique(array_map('intval', $templateIds))));
	}
}
