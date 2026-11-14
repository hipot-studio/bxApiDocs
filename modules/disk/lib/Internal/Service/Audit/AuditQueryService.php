<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Dto\Audit\AuditCard;
use Bitrix\Disk\Internal\Dto\Audit\AuditFilter;
use Bitrix\Disk\Internal\Dto\Audit\AuditListItem;
use Bitrix\Disk\Internal\Dto\Audit\AuditPage;
use Bitrix\Disk\Internal\Dto\Audit\AuditState;
use Bitrix\Disk\Internal\Entity\Audit\AuditChannel;
use Bitrix\Disk\Internal\Entity\Audit\AuditInitiator;
use Bitrix\Disk\Internal\Entity\Audit\AuditResult;
use Bitrix\Disk\Internal\Entity\Audit\AuditTarget;
use Bitrix\Disk\Internal\Repository\Audit\AuditEventRepository;
use Bitrix\Disk\Ui\Avatar;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\UserTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Closure;
use InvalidArgumentException;
use Throwable;

final class AuditQueryService
{
	private const AVATAR_SIZE = 40;

	public const ERROR_NOT_FOUND = 'not_found';
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_LICENSE_UNAVAILABLE = 'license_unavailable';
	public const ERROR_TEMPORARY = 'temporary_error';

	private readonly AuditEventRepository $repository;
	private readonly AuditAvailabilityService $availabilityService;
	private readonly AuditAccessService $accessService;
	private readonly AuditDetailsProjector $detailsProjector;
	private readonly AuditObjectLinkResolver $objectLinkResolver;
	private readonly AuditAccessSubjectResolver $accessSubjectResolver;
	private readonly AuditContextEntityResolver $contextEntityResolver;
	private readonly AuditStorageNameResolver $storageNameResolver;
	private readonly Closure $wasEverEnabledResolver;

	public function __construct(
		?AuditEventRepository $repository = null,
		?AuditAvailabilityService $availabilityService = null,
		?AuditAccessService $accessService = null,
		?AuditDetailsProjector $detailsProjector = null,
		?AuditObjectLinkResolver $objectLinkResolver = null,
		?callable $wasEverEnabledResolver = null,
		?AuditAccessSubjectResolver $accessSubjectResolver = null,
		?AuditContextEntityResolver $contextEntityResolver = null,
		?AuditStorageNameResolver $storageNameResolver = null,
	)
	{
		$this->repository = $repository ?? new AuditEventRepository();
		$this->availabilityService = $availabilityService ?? new AuditAvailabilityService();
		$this->accessService = $accessService ?? new AuditAccessService($this->availabilityService);
		$this->detailsProjector = $detailsProjector ?? new AuditDetailsProjector();
		$this->objectLinkResolver = $objectLinkResolver ?? new AuditObjectLinkResolver();
		$this->accessSubjectResolver = $accessSubjectResolver ?? new AuditAccessSubjectResolver(
			avatarResolver: fn(int $userId): ?string => $this->resolveUserAvatar($userId),
		);
		$this->contextEntityResolver = $contextEntityResolver ?? new AuditContextEntityResolver();
		$this->storageNameResolver = $storageNameResolver ?? new AuditStorageNameResolver();
		$this->wasEverEnabledResolver = Closure::fromCallable(
			$wasEverEnabledResolver ?? static fn(): bool => (new AuditSettingsService())->wasEverEnabled(),
		);
	}

	public function getById(int $id, ?CurrentUser $currentUser = null): Result
	{
		$result = new Result();
		if (!$this->accessService->isAdmin($currentUser))
		{
			return $result->addError(new Error('Access to the disk audit event is denied.', self::ERROR_ACCESS_DENIED));
		}

		if (!$this->availabilityService->isLicenseAvailable())
		{
			return $result->addError(
				new Error('The disk audit log is unavailable for the current license.', self::ERROR_LICENSE_UNAVAILABLE),
			);
		}

		if ($id <= 0)
		{
			return $result->addError(new Error('The disk audit event was not found.', self::ERROR_NOT_FOUND));
		}

		try
		{
			$row = $this->repository->findById($id);
			if ($row === null)
			{
				return $result->addError(new Error('The disk audit event was not found.', self::ERROR_NOT_FOUND));
			}

			$item = $this->mapRow($row);
			$details = $this->detailsProjector->project(
				$item->id,
				$item->eventCode,
				$this->nullableString($row['DETAILS'] ?? null),
			);
			$card = new AuditCard(
				item: $item,
				ip: $this->nullableString($row['IP'] ?? null),
				details: $details,
				objectLink: $this->objectLinkResolver->resolve(
					$item->targetType,
					$item->targetId,
					$currentUser,
				),
				pathSnapshot: $this->nullableString($row['TARGET_PATH'] ?? null),
				storageName: $this->nullableString($row['STORAGE_NAME'] ?? null),
				parentId: $this->nullableString($row['TARGET_PARENT_ID'] ?? null),
				initiatorAvatar: $this->resolveInitiatorAvatar($item),
				accessSubject: $this->accessSubjectResolver->resolve($details),
				contextEntity: $this->contextEntityResolver->resolve($item),
				storageNames: $this->storageNameResolver->resolve($details),
			);
		}
		catch (Throwable)
		{
			return $result->addError(
				new Error('The disk audit event could not be read.', self::ERROR_TEMPORARY),
			);
		}

		return $result->setData(['card' => $card]);
	}

	/** A person keeps the photo the product shows for them elsewhere; a machine has none. */
	private function resolveInitiatorAvatar(AuditListItem $item): ?string
	{
		$isPerson = in_array(
			$item->initiatorType,
			[AuditInitiator::TYPE_EMPLOYEE, AuditInitiator::TYPE_EXTERNAL_USER],
			true,
		);
		if (!$isPerson || preg_match('/^[0-9]+$/D', $item->initiatorId) !== 1)
		{
			return null;
		}

		return $this->resolveUserAvatar((int)$item->initiatorId);
	}

	private function resolveUserAvatar(int $userId): ?string
	{
		// The photo is an extra to the card: a failing lookup leaves the initials, not an error.
		try
		{
			$user = UserTable::getRow([
				'select' => ['PERSONAL_PHOTO'],
				'filter' => ['=ID' => $userId],
			]);

			// No stock picture for a person without a photo: the card draws their initials, as the
			// profile and the messenger do.
			$src = Avatar::getSrc((int)($user['PERSONAL_PHOTO'] ?? 0), self::AVATAR_SIZE, self::AVATAR_SIZE);
		}
		catch (Throwable)
		{
			return null;
		}

		return is_string($src) && $src !== '' ? $src : null;
	}

	public function list(AuditFilter $filter, ?CurrentUser $currentUser = null): AuditPage
	{
		if (!$this->accessService->isAdmin($currentUser))
		{
			return $this->createEmptyPage(AuditState::AccessDenied, $filter);
		}

		if (!$this->availabilityService->isLicenseAvailable())
		{
			return $this->createEmptyPage(AuditState::LicenseUnavailable, $filter);
		}

		$auditEnabled = $this->availabilityService->isAuditEnabled();
		if (!$auditEnabled && !(bool)($this->wasEverEnabledResolver)())
		{
			return $this->createEmptyPage(AuditState::Disabled, $filter);
		}

		try
		{
			$total = $this->repository->count($filter);
			$rows = $this->repository->findPage($filter);
			$items = array_map($this->mapRow(...), $rows);
		}
		catch (Throwable)
		{
			return $this->createEmptyPage(AuditState::TemporaryError, $filter);
		}

		$state = $this->resolveState($auditEnabled, $filter, $items);

		return new AuditPage(
			state: $state,
			items: $items,
			page: $filter->page,
			pageSize: $filter->pageSize,
			total: $total,
		);
	}

	public function getFilterOptions(?CurrentUser $currentUser = null): Result
	{
		$result = new Result();
		if (!$this->accessService->isAdmin($currentUser))
		{
			return $result->addError(new Error('Access to the disk audit log is denied.', self::ERROR_ACCESS_DENIED));
		}

		if (!$this->availabilityService->isLicenseAvailable())
		{
			return $result->addError(
				new Error('The disk audit log is unavailable for the current license.', self::ERROR_LICENSE_UNAVAILABLE),
			);
		}

		return $result->setData([
			'state' => $this->availabilityService->isAuditEnabled()
				? AuditState::Ok->value
				: AuditState::Disabled->value,
			'eventCode' => AuditEventCodeRegistry::getFilterCodes(),
			'targetType' => AuditTarget::types(),
			'channel' => [
				AuditChannel::WEB,
				AuditChannel::MOBILE,
				AuditChannel::DESKTOP,
				AuditChannel::API,
				AuditChannel::SYSTEM,
				AuditChannel::BIZPROC,
			],
			'result' => [AuditResult::SUCCESS],
			'initiatorType' => [
				AuditInitiator::TYPE_EMPLOYEE,
				AuditInitiator::TYPE_EXTERNAL_USER,
				AuditInitiator::TYPE_APPLICATION,
				AuditInitiator::TYPE_SYSTEM,
			],
		]);
	}

	private function mapRow(array $row): AuditListItem
	{
		if (!($row['OCCURRED_AT'] ?? null) instanceof DateTime)
		{
			throw new InvalidArgumentException('Disk audit event occurrence time is invalid.');
		}

		return new AuditListItem(
			id: (int)($row['ID'] ?? 0),
			eventCode: (string)($row['EVENT_CODE'] ?? ''),
			occurredAt: $row['OCCURRED_AT'],
			initiatorType: (string)($row['INITIATOR_TYPE'] ?? ''),
			initiatorId: (string)($row['INITIATOR_ID'] ?? ''),
			initiatorName: (string)($row['INITIATOR_NAME'] ?? ''),
			channel: (string)($row['CHANNEL'] ?? ''),
			contextType: (string)($row['CONTEXT'] ?? ''),
			contextEntityType: $this->nullableString($row['CONTEXT_ENTITY_TYPE'] ?? null),
			contextEntityId: $this->nullableString($row['CONTEXT_ENTITY_ID'] ?? null),
			contextEntityName: $this->nullableString($row['CONTEXT_ENTITY_NAME'] ?? null),
			targetType: (string)($row['TARGET_TYPE'] ?? ''),
			targetId: (string)($row['TARGET_ID'] ?? ''),
			targetName: (string)($row['TARGET_NAME'] ?? ''),
			result: (string)($row['RESULT'] ?? ''),
			storageId: isset($row['STORAGE_ID']) ? (int)$row['STORAGE_ID'] : null,
			storageName: $this->nullableString($row['STORAGE_NAME'] ?? null),
		);
	}

	private function resolveState(bool $auditEnabled, AuditFilter $filter, array $items): AuditState
	{
		if (!$auditEnabled)
		{
			return AuditState::Disabled;
		}

		if ($items === [] && $filter->page === 1 && !$this->hasActiveFilter($filter))
		{
			return AuditState::Empty;
		}

		return AuditState::Ok;
	}

	private function hasActiveFilter(AuditFilter $filter): bool
	{
		return $filter->from !== null
			|| $filter->to !== null
			|| $filter->initiator !== null
			|| $filter->eventCode !== null
			|| $filter->targetType !== null
			|| $filter->storageId !== null
			|| $filter->channel !== null
			|| $filter->result !== null
			|| $filter->search !== null;
	}

	private function nullableString(mixed $value): ?string
	{
		return $value === null ? null : (string)$value;
	}

	private function createEmptyPage(AuditState $state, AuditFilter $filter): AuditPage
	{
		return new AuditPage(
			state: $state,
			items: [],
			page: $filter->page,
			pageSize: $filter->pageSize,
			total: 0,
		);
	}
}
