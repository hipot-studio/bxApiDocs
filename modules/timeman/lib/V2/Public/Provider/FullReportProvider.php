<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider;

use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Entity;
use Bitrix\Timeman\V2\Internal\Repository\FullReportRepository;
use Bitrix\Timeman\V2\Internal\Service\FullReportFacade;
use Bitrix\Timeman\V2\Internal\Service\FullReportUserService;
use Bitrix\Timeman\V2\Public\Dto\FullReport\FullReport;
use Bitrix\Timeman\V2\Public\Dto\FullReport\FullReportCollection;
use Bitrix\Timeman\V2\Public\Dto\Mapper\DtoMapper;
use Bitrix\Timeman\V2\Public\Dto\User\User;
use Bitrix\Timeman\V2\Public\Dto\User\UserCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;

final class FullReportProvider
{
	private readonly FullReportRepository $repository;
	private readonly FullReportFacade $fullReportFacade;
	private readonly FullReportUserService $fullReportUserService;
	private readonly DtoMapper $dtoMapper;

	public function __construct()
	{
		$container = Container::getInstance();

		$this->repository = $container->getFullReportRepository();
		$this->fullReportFacade = $container->getFullReportFacade();
		$this->fullReportUserService = $container->getFullReportUserService();
		$this->dtoMapper = new DtoMapper();
	}

	public function getReports(ListParams $params): FullReportCollection
	{
		$reports = $this->repository->getList(
			select: $params->getSelect(),
			filter: $params->getFilter(),
			sort: $params->getSort(),
			offset: $params->getOffset(),
			limit: $params->getLimit(),
		);

		$participantsByUserId = $this->fullReportUserService->getParticipantsDataByUserIds(
			$this->collectUserIds($reports),
		);

		return new FullReportCollection(
			...array_map(
				fn (Entity\FullReport\FullReport $report): FullReport => $this->mapEntityReportToDto(
					$report,
					$participantsByUserId[(int)$report->userId] ?? null,
				),
				iterator_to_array($reports),
			),
		);
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

	public function getReportToSend(int $userId, bool $force = false): FullReport
	{
		return $this->mapLegacyReportToDto(
			$this->fullReportFacade->getReportToSend($userId, $force),
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
		/** @var FullReport $dto */
		$dto = $this->dtoMapper->mapToDto($payload, FullReport::class);

		return $dto;
	}
}
