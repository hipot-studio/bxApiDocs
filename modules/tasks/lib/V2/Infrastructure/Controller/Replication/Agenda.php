<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Replication;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\Rule\RegularTasksSectionEnabled;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Service\Replication\Agenda\AgendaService;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionActivity;
use Bitrix\Tasks\V2\Internal\Service\Replication\Section\SectionRole;

/**
 * Хвост agenda раздела «Регулярные задачи» (API-S5, P3.T2).
 *
 * Независимый хронологический хвост от «сегодня» с курсорной пагинацией (Q-2).
 * Период графика не прокидывается. Срез и видимость применяются на сервере.
 */
class Agenda extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Replication.Agenda.tail
	 */
	#[RegularTasksSectionEnabled]
	#[CloseSession]
	public function tailAction(
		AgendaService $agendaService,
		?string $roleId = null,
		?string $group = null,
		?string $cursor = null,
		int $limit = 20,
		int $tzOffset = 0,
		?string $statusFilter = null,
		?int $templateId = null,
		?int $from = null,
		?int $to = null,
		?bool $replicateActive = null,
		?string $search = null,
		?string $replicateStatus = null,
		array $filter = [],
	): array
	{
		$from = $this->normalizeNonNegative($from);
		$to = $this->normalizeNonNegative($to);

		// templateId/from/to — action parameters, not $request->get(): the section posts them
		// in the JSON body (apiClient contentType=json), which $request->get() never exposes —
		// only the Engine binds them. Same binding as the Grid controller; reading them via
		// $request->get() here silently pinned templateId to null (drawers showed the whole
		// section) and dropped the from/to window (hover popover showed the whole tail).
		return $agendaService->getTail(
			viewerId: $this->userId,
			roleFilter: SectionRole::resolveMemberType($roleId),
			replicateActive: SectionActivity::resolve($replicateStatus, $replicateActive ?? true),
			tzOffset: $tzOffset,
			group: $this->normalizeGroup($group),
			cursor: $cursor,
			limit: $limit,
			templateId: $this->normalizeNonNegative($templateId),
			from: $from,
			to: $to,
			statusFilter: $this->normalizeStatusFilter($statusFilter),
			search: $search,
			filter: SectionFilter::normalize($filter),
		);
	}

	/**
	 * Опциональная неотрицательная граница: отрицательное → null (templateId — id шаблона;
	 * from/to — unix-границы окна в поясе просматривающего, обе >= 0). Ноль сохраняется.
	 */
	private function normalizeNonNegative(?int $value): ?int
	{
		if ($value === null)
		{
			return null;
		}

		return $value >= 0 ? $value : null;
	}

	private function normalizeGroup(?string $group): ?string
	{
		return match ($group)
		{
			AgendaService::GROUP_TODAY,
			AgendaService::GROUP_PAST,
			AgendaService::GROUP_FUTURE => $group,
			default => null,
		};
	}

	private function normalizeStatusFilter(?string $statusFilter): string
	{
		return match ($statusFilter)
		{
			AgendaService::FILTER_COMPLETED,
			AgendaService::FILTER_CREATED,
			AgendaService::FILTER_PLANNED,
			AgendaService::FILTER_ERROR => $statusFilter,
			default => AgendaService::FILTER_ALL,
		};
	}
}
