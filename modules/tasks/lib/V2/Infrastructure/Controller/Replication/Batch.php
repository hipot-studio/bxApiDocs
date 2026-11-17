<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Replication;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\Rule\RegularTasksSectionEnabled;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Service\Replication\Batch\BatchReplicateStateService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Batch\BatchTemplateActionService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\ReplicationRowService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionActivity;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionListQuery;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionRole;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentSubject;

/**
 * Групповые действия раздела «Регулярные задачи» (API-S6, API-S7, P4).
 *
 * Каждое действие проверяет права per-row; недоступные не применяются и возвращаются
 * как `denied` (ERR-S-011), частичная недоступность — в `results`, не общий отказ.
 *
 * `forAll` применяет действие ко всему срезу (текущий фильтр раздела), а не к странице:
 * ID разрешаются на сервере через {@see ReplicationRowService::getSliceTemplateIds()},
 * клиентский список строк при этом игнорируется.
 */
class Batch extends BaseController
{
	public const ERROR_BATCH_LIMIT_EXCEEDED = 'TASKS_REGULAR_BATCH_LIMIT_EXCEEDED';
	public const ERROR_ARGUMENT_LIMIT_EXCEEDED = 'TASKS_REGULAR_BATCH_ARGUMENT_LIMIT_EXCEEDED';

	private const MAX_BATCH_SIZE = 5000;
	private const MAX_TEMPLATE_ID_ARGUMENTS = 5000;

	/**
	 * @ajaxAction tasks.V2.Replication.Batch.setReplicateState
	 */
	#[RegularTasksSectionEnabled]
	#[CloseSession]
	public function setReplicateStateAction(
		BatchReplicateStateService $service,
		ReplicationRowService $rowService,
		array $templateIds = [],
		bool $replicate = true,
		bool $forAll = false,
		?string $roleId = null,
		bool $replicateActive = true,
		?string $search = null,
		?string $replicateStatus = null,
		array $filter = [],
	): ?array
	{
		$ids = $this->resolveTemplateIds(
			$rowService,
			$templateIds,
			$forAll,
			$roleId,
			$replicateActive,
			$search,
			$replicateStatus,
			$filter,
		);
		if ($ids === null)
		{
			return null;
		}

		return [
			'results' => $service->setState($ids, $this->userId, $replicate),
		];
	}

	/**
	 * @ajaxAction tasks.V2.Replication.Batch.run
	 */
	#[RegularTasksSectionEnabled]
	#[CloseSession]
	public function runAction(
		BatchTemplateActionService $service,
		ReplicationRowService $rowService,
		array $templateIds = [],
		string $batchAction = '',
		array $payload = [],
		bool $forAll = false,
		?string $roleId = null,
		bool $replicateActive = true,
		?string $search = null,
		?string $replicateStatus = null,
		array $filter = [],
	): ?array
	{
		// Параметр называется `batchAction`, а не `action`: `action` зарезервирован
		// маршрутизатором main.engine (имя ajax-экшена в query) и перетирал бы значение.
		$ids = $this->resolveTemplateIds(
			$rowService,
			$templateIds,
			$forAll,
			$roleId,
			$replicateActive,
			$search,
			$replicateStatus,
			$filter,
		);
		if ($ids === null)
		{
			return null;
		}

		return [
			'results' => $service->run(
				templateIds: $ids,
				action: $batchAction,
				payload: $payload,
				userId: $this->userId,
				authorSubject: AuthorAssignmentSubject::fromAuthenticatedUser(),
			),
		];
	}

	/**
	 * @param int[] $templateIds
	 * @return int[]|null
	 */
	private function resolveTemplateIds(
		ReplicationRowService $rowService,
		array $templateIds,
		bool $forAll,
		?string $roleId,
		bool $replicateActive,
		?string $search,
		?string $replicateStatus,
		array $filter = [],
	): ?array
	{
		if (count($templateIds) > self::MAX_TEMPLATE_ID_ARGUMENTS)
		{
			$this->addLimitError(
				self::MAX_TEMPLATE_ID_ARGUMENTS,
				self::ERROR_ARGUMENT_LIMIT_EXCEEDED,
				'TASKS_V2_REPLICATION_BATCH_ARGUMENT_LIMIT_EXCEEDED',
			);

			return null;
		}

		if (!$forAll)
		{
			return $this->validateBatchSize($this->normalizeTemplateIds($templateIds));
		}

		$search = is_string($search) ? trim($search) : '';

		$query = new SectionListQuery(
			viewerId: $this->userId,
			roleFilter: SectionRole::resolveMemberType($roleId),
			replicateActive: SectionActivity::resolve($replicateStatus, $replicateActive),
			search: $search === '' ? null : $search,
			filter: SectionFilter::normalize($filter),
		);

		return $this->validateBatchSize(
			$rowService->getSliceTemplateIds($query, self::MAX_BATCH_SIZE + 1),
		);
	}

	/**
	 * @param mixed[] $templateIds
	 * @return int[]
	 */
	private function normalizeTemplateIds(array $templateIds): array
	{
		$ids = [];

		foreach ($templateIds as $templateId)
		{
			$id = filter_var(
				$templateId,
				FILTER_VALIDATE_INT,
				['options' => ['min_range' => 1]],
			);
			if ($id === false)
			{
				continue;
			}

			$ids[$id] = $id;
			if (count($ids) > self::MAX_BATCH_SIZE)
			{
				break;
			}
		}

		return array_values($ids);
	}

	/**
	 * @param int[] $templateIds
	 * @return int[]|null
	 */
	private function validateBatchSize(array $templateIds): ?array
	{
		if (count($templateIds) <= self::MAX_BATCH_SIZE)
		{
			return $templateIds;
		}

		$this->addLimitError(
			self::MAX_BATCH_SIZE,
			self::ERROR_BATCH_LIMIT_EXCEEDED,
			'TASKS_V2_REPLICATION_BATCH_LIMIT_EXCEEDED',
		);

		return null;
	}

	private function addLimitError(int $limit, string $code, string $messageCode): void
	{
		$this->addError(
			new Error(
				(string)Loc::getMessage($messageCode, ['#LIMIT#' => $limit]),
				$code,
			),
		);
	}
}
