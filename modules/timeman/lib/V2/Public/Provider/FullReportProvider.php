<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider;

use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Entity;
use Bitrix\Timeman\V2\Internal\Repository\FullReportRepository;
use Bitrix\Timeman\V2\Internal\Service\FullReportFacade;
use Bitrix\Timeman\V2\Internal\Service\FullReportUserService;
use Bitrix\Timeman\V2\Internal\Service\ReportTextNormalizerService;
use Bitrix\Timeman\V2\Public\Dto\FullReport\FullReport;
use Bitrix\Timeman\V2\Public\Dto\FullReport\FullReportCollection;
use Bitrix\Timeman\V2\Public\Dto\FullReport\UserReports;
use Bitrix\Timeman\V2\Public\Dto\FullReport\UserReportsCollection;
use Bitrix\Timeman\V2\Public\Dto\FullReport\UserReportsPage;
use Bitrix\Timeman\V2\Public\Dto\Mapper\DtoMapper;
use Bitrix\Timeman\V2\Public\Dto\User\User;
use Bitrix\Timeman\V2\Public\Dto\User\UserCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\FullReport\Filter;
use Bitrix\Timeman\V2\Public\Provider\Params\FullReport\Select;
use Bitrix\Timeman\V2\Public\Provider\Params\FullReport\Sort;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;

final class FullReportProvider
{
	private readonly FullReportRepository $repository;
	private readonly FullReportFacade $fullReportFacade;
	private readonly FullReportUserService $fullReportUserService;
	private readonly ReportTextNormalizerService $reportTextNormalizerService;
	private readonly DtoMapper $dtoMapper;

	public function __construct()
	{
		$container = Container::getInstance();

		$this->repository = $container->getFullReportRepository();
		$this->fullReportFacade = $container->getFullReportFacade();
		$this->fullReportUserService = $container->getFullReportUserService();
		$this->reportTextNormalizerService = $container->getReportTextNormalizerService();
		$this->dtoMapper = new DtoMapper();
	}

	public function getReports(ListParams $params): FullReportCollection
	{
		return $this->mapEntityReports(
			$this->repository->getList(
				select: $params->getSelect(),
				filter: $params->getFilter(),
				sort: $params->getSort(),
				offset: $params->getOffset(),
				limit: $params->getLimit(),
			),
		);
	}

	public function getSubordinateUserReports(
		int $managerUserId,
		Filter $filter,
		Sort $sort,
		Select $select,
		int $userOffset = 0,
		int $userLimit = 5,
	): UserReportsPage
	{
		$filter = $filter->withActiveOnly(true);

		$accessibleUserIds = $this->getAccessibleSubordinateUserIds($managerUserId);
		if (empty($accessibleUserIds))
		{
			return $this->createEmptyUserReportsPage($userOffset, $userLimit);
		}

		$requestedUserIds = $filter->getUserIds();
		if (!empty($requestedUserIds))
		{
			$accessibleUserIds = array_values(array_intersect($accessibleUserIds, $requestedUserIds));
			if (empty($accessibleUserIds))
			{
				return $this->createEmptyUserReportsPage($userOffset, $userLimit);
			}
		}

		$shouldPrioritizeManager = empty($requestedUserIds) || in_array($managerUserId, $requestedUserIds, true);

		$pageUserIds = $this->repository->getDistinctUserIds(
			filter: $filter->withUserIds($accessibleUserIds)->prepareFilter(),
			offset: $userOffset,
			limit: $userLimit + 1,
			priorityUserId: $shouldPrioritizeManager ? $managerUserId : null,
		);

		$hasMore = count($pageUserIds) > $userLimit;
		if ($hasMore)
		{
			$pageUserIds = array_slice($pageUserIds, 0, $userLimit);
		}

		if (empty($pageUserIds))
		{
			return $this->createEmptyUserReportsPage($userOffset, $userLimit);
		}

		$reports = $this->mapEntityReports(
			$this->repository->getAll(
				select: $select->prepareSelect(),
				filter: $filter->withUserIds($pageUserIds)->prepareFilter(),
				sort: $sort->prepareSort(),
			),
		);

		$groupedReports = [];
		foreach ($reports as $report)
		{
			$groupedReports[$report->userId][] = $report;
		}

		$items = [];
		foreach ($pageUserIds as $pageUserId)
		{
			$userReports = $groupedReports[$pageUserId] ?? [];
			if (empty($userReports))
			{
				continue;
			}

			$firstReport = reset($userReports);
			$items[] = new UserReports(
				userId: $pageUserId,
				user: $firstReport instanceof FullReport ? $firstReport->fromUser : null,
				reports: new FullReportCollection(...$userReports),
			);
		}

		return new UserReportsPage(
			items: new UserReportsCollection(...$items),
			offset: $userOffset,
			limit: $userLimit,
			hasMore: $hasMore,
			nextOffset: $hasMore ? $userOffset + count($pageUserIds) : null,
		);
	}

	public function hasOtherSubordinates(int $managerUserId): bool
	{
		foreach ($this->getAccessibleSubordinateUserIds($managerUserId) as $userId)
		{
			if ($userId !== $managerUserId)
			{
				return true;
			}
		}

		return false;
	}

	public function getById(int $reportId): ?FullReport
	{
		$report = $this->repository->getById($reportId);

		if (!$report)
		{
			return null;
		}

		$participantData = $this->fullReportUserService->getParticipantsDataByUserIds([$report->userId])[$report->userId] ?? null;

		return $this->mapEntityReportToDto($report, $participantData);
	}

	public function getReportToSend(int $userId, bool $force = false, bool $withDraftFallback = false): FullReport
	{
		return $this->mapLegacyReportToDto(
			$this->fullReportFacade->getReportToSend($userId, $force, $withDraftFallback),
		);
	}

	/**
	 * @param iterable<Entity\FullReport\FullReport> $reports
	 */
	private function mapEntityReports(iterable $reports): FullReportCollection
	{
		$reports = is_array($reports) ? $reports : iterator_to_array($reports);

		$participantsByUserId = $this->fullReportUserService->getParticipantsDataByUserIds(
			$this->collectUserIds($reports),
		);

		return new FullReportCollection(
			...array_map(
				fn (Entity\FullReport\FullReport $report): FullReport => $this->mapEntityReportToDto(
					$report,
					$participantsByUserId[(int)$report->userId] ?? null,
				),
				$reports,
			),
		);
	}

	/**
	 * @param ?array{fromUser: Entity\User, toUsers: Entity\UserCollection} $participantData
	 */
	private function mapEntityReportToDto(
		Entity\FullReport\FullReport $report,
		?array $participantData,
	): FullReport
	{
		return $this->buildFullReportDto([
			...get_object_vars($report),
			...$this->mapInternalParticipantsToDtoPayload($participantData),
		]);
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function mapLegacyReportToDto(array $report): FullReport
	{
		return $this->buildFullReportDto([
			...$report,
			'fromUser' => $this->mapLegacyUserToPublicDto($report['fromUser'] ?? null),
			'toUsers' => $this->mapLegacyUsersToPublicDtoCollection($report['toUsers'] ?? null),
		]);
	}

	/**
	 * @param ?array{fromUser: Entity\User, toUsers: Entity\UserCollection} $participantData
	 * @return array{fromUser?: User|null, toUsers?: UserCollection|null}
	 */
	private function mapInternalParticipantsToDtoPayload(?array $participantData): array
	{
		if ($participantData === null)
		{
			return [];
		}

		return [
			'fromUser' => $this->mapInternalUserToPublicDto($participantData['fromUser']),
			'toUsers' => $this->mapInternalUsersToPublicDtoCollection($participantData['toUsers']),
		];
	}

	private function mapInternalUserToPublicDto(Entity\User $user): User
	{
		return new User(
			id: (int)$user->id,
			name: $user->name,
			photo: $user->image?->src,
		);
	}

	private function mapInternalUsersToPublicDtoCollection(Entity\UserCollection $users): UserCollection
	{
		return new UserCollection(
			...array_map(
				fn (Entity\User $user): User => $this->mapInternalUserToPublicDto($user),
				iterator_to_array($users),
			),
		);
	}

	private function mapLegacyUserToPublicDto(mixed $user): ?User
	{
		if (!is_array($user))
		{
			return null;
		}

		return new User(
			id: (int)($user['id'] ?? $user['ID'] ?? 0),
			name: is_string($user['name'] ?? $user['NAME'] ?? null) ? (string)($user['name'] ?? $user['NAME']) : null,
			photo: is_string($user['photo'] ?? $user['PHOTO'] ?? null) ? (string)($user['photo'] ?? $user['PHOTO']) : null,
		);
	}

	private function mapLegacyUsersToPublicDtoCollection(mixed $users): ?UserCollection
	{
		if (!is_array($users))
		{
			return null;
		}

		$items = [];
		foreach ($users as $user)
		{
			$mappedUser = $this->mapLegacyUserToPublicDto($user);
			if ($mappedUser)
			{
				$items[] = $mappedUser;
			}
		}

		return new UserCollection(...$items);
	}

	/**
	 * @return array<int, int>
	 */
	private function getAccessibleSubordinateUserIds(int $managerUserId): array
	{
		$userIds = $this->fullReportUserService->getUserIdsAccessibleToRead($managerUserId);
		$userIds = array_filter($userIds, static fn (int $userId): bool => $userId > 0);

		return array_values($userIds);
	}

	private function createEmptyUserReportsPage(int $userOffset, int $userLimit): UserReportsPage
	{
		return new UserReportsPage(
			items: new UserReportsCollection(),
			offset: $userOffset,
			limit: $userLimit,
			hasMore: false,
			nextOffset: null,
		);
	}

	/**
	 * @param iterable<Entity\FullReport\FullReport> $reports
	 * @return array<int, int>
	 */
	private function collectUserIds(iterable $reports): array
	{
		$userIds = [];
		foreach ($reports as $report)
		{
			$userIds[] = (int)$report->userId;
		}

		return $userIds;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function buildFullReportDto(array $payload): FullReport
	{
		$payload['report'] = $this->reportTextNormalizerService->normalize(
			is_string($payload['report'] ?? null) ? $payload['report'] : null,
		);

		$payload['plans'] = $this->reportTextNormalizerService->normalize(
			is_string($payload['plans'] ?? null) ? $payload['plans'] : null,
		);

		/** @var FullReport $dto */
		$dto = $this->dtoMapper->mapToDto($payload, FullReport::class);

		return $dto;
	}
}
