<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Internal\Dto\Audit\AuditFilter;
use Bitrix\Disk\Internal\Service\Audit\AuditAccessService;
use Bitrix\Disk\Internal\Service\Audit\AuditAvailabilityService;
use Bitrix\Disk\Internal\Service\Audit\AuditPageInstanceTokenCodec;
use Bitrix\Disk\Internal\Service\Audit\AuditPageOpenedRecorder;
use Bitrix\Disk\Internal\Service\Audit\AuditQueryService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Main\UI\PageNavigation;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class Audit extends Controller
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_AUDIT_FEATURE_DISABLED = 'AUDIT_FEATURE_DISABLED';
	public const ERROR_AUDIT_LICENSE_UNAVAILABLE = 'AUDIT_LICENSE_UNAVAILABLE';
	public const ERROR_INVALID_FILTER = 'INVALID_AUDIT_FILTER';
	public const ERROR_NOT_FOUND = 'AUDIT_EVENT_NOT_FOUND';
	public const ERROR_INVALID_PAGE_INSTANCE = 'INVALID_AUDIT_PAGE_INSTANCE';
	public const ERROR_TEMPORARY = 'AUDIT_TEMPORARY_ERROR';

	public function __construct(
		?Request $request = null,
		private readonly ?AuditQueryService $queryService = null,
		private readonly ?AuditAvailabilityService $availabilityService = null,
		private readonly ?AuditAccessService $accessService = null,
		private readonly ?AuditPageInstanceTokenCodec $pageInstanceTokenCodec = null,
		private readonly ?AuditPageOpenedRecorder $pageOpenedRecorder = null,
	)
	{
		parent::__construct($request);
	}

	public function configureActions(): array
	{
		return [
			'list' => ['prefilters' => $this->createPostPrefilters()],
			'get' => ['prefilters' => $this->createPostPrefilters()],
			'getFilterOptions' => ['prefilters' => $this->createPostPrefilters()],
			'recordPageOpened' => ['prefilters' => $this->createPostPrefilters()],
		];
	}

	public function listAction(
		array $filter,
		PageNavigation $pageNavigation,
		CurrentUser $currentUser,
	): ?array
	{
		if (!$this->checkReadAccess($currentUser))
		{
			return null;
		}

		try
		{
			$filter['page'] ??= $pageNavigation->getCurrentPage();
			$filter['pageSize'] ??= $pageNavigation->getPageSize();
			$auditFilter = AuditFilter::createFromArray($filter, self::resolveAdministratorTimeZone());
			$page = $this->getQueryService()->list($auditFilter, $currentUser);
			$response = $page->jsonSerialize();
			$response['items'] = array_map(
				static fn(object $item): array => $item->jsonSerialize(),
				$page->items,
			);

			return $response;
		}
		catch (InvalidArgumentException)
		{
			$this->addError($this->createError(
				'Invalid disk audit filter.',
				self::ERROR_INVALID_FILTER,
				422,
			));

			return null;
		}
	}

	public function getAction(int $id, CurrentUser $currentUser): ?array
	{
		if (!$this->checkReadAccess($currentUser))
		{
			return null;
		}

		$result = $this->getQueryService()->getById($id, $currentUser);
		if (!$result->isSuccess())
		{
			$this->addErrors(array_map($this->mapQueryError(...), $result->getErrors()));

			return null;
		}

		return $result->getData()['card']->jsonSerialize();
	}

	public function getFilterOptionsAction(CurrentUser $currentUser): ?array
	{
		if (!$this->checkReadAccess($currentUser))
		{
			return null;
		}

		$result = $this->getQueryService()->getFilterOptions($currentUser);
		if (!$result->isSuccess())
		{
			$this->addErrors(array_map($this->mapQueryError(...), $result->getErrors()));

			return null;
		}

		return $result->getData();
	}

	public function recordPageOpenedAction(string $pageInstanceId, CurrentUser $currentUser): ?array
	{
		if (!$this->checkReadAccess($currentUser))
		{
			return null;
		}

		try
		{
			$pageInstance = $this->getPageInstanceTokenCodec()->verify(
				$pageInstanceId,
				(int)$currentUser->getId(),
			);
			$result = $this->getPageOpenedRecorder()->record(
				$pageInstance['instanceId'],
				$pageInstance['issuedAt'],
				$currentUser,
			);
		}
		catch (InvalidArgumentException)
		{
			$this->addError($this->createError(
				'Invalid disk audit page instance.',
				self::ERROR_INVALID_PAGE_INSTANCE,
				422,
			));

			return null;
		}
		catch (Throwable)
		{
			$this->addError($this->createError(
				'Disk audit page opening could not be recorded.',
				self::ERROR_TEMPORARY,
				503,
			));

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addError($this->createError(
				'Disk audit page opening could not be recorded.',
				self::ERROR_TEMPORARY,
				503,
			));

			return null;
		}

		return ['recorded' => true];
	}

	private function checkReadAccess(CurrentUser $currentUser): bool
	{
		if (!$this->getAccessService()->isAdmin($currentUser))
		{
			$this->addError($this->createError('Access denied.', self::ERROR_ACCESS_DENIED, 403));

			return false;
		}

		if (!$this->getAvailabilityService()->isFeatureEnabled())
		{
			$this->addError($this->createError(
				'Audit feature is disabled.',
				self::ERROR_AUDIT_FEATURE_DISABLED,
				404,
			));

			return false;
		}

		if (!$this->getAvailabilityService()->isLicenseAvailable())
		{
			$this->addError($this->createError(
				'Audit license is unavailable.',
				self::ERROR_AUDIT_LICENSE_UNAVAILABLE,
				423,
			));

			return false;
		}

		return true;
	}

	private function createPostPrefilters(): array
	{
		return [
			new Authentication(),
			new Csrf(),
			new HttpMethod([HttpMethod::METHOD_POST]),
		];
	}

	private function getQueryService(): AuditQueryService
	{
		return $this->queryService ?? ServiceLocator::getInstance()->get(AuditQueryService::class);
	}

	private function getAvailabilityService(): AuditAvailabilityService
	{
		return $this->availabilityService ?? ServiceLocator::getInstance()->get(AuditAvailabilityService::class);
	}

	private function getAccessService(): AuditAccessService
	{
		return $this->accessService ?? ServiceLocator::getInstance()->get(AuditAccessService::class);
	}

	private function getPageInstanceTokenCodec(): AuditPageInstanceTokenCodec
	{
		return $this->pageInstanceTokenCodec ?? new AuditPageInstanceTokenCodec();
	}

	private function getPageOpenedRecorder(): AuditPageOpenedRecorder
	{
		return $this->pageOpenedRecorder ?? new AuditPageOpenedRecorder();
	}

	/**
	 * Resolves the current administrator time zone used by audit filters.
	 *
	 * @return DateTimeZone
	 */
	public static function resolveAdministratorTimeZone(): DateTimeZone
	{
		global $USER;

		$timeZoneId = '';
		if (\CTimeZone::Enabled() && is_object($USER))
		{
			$timeZoneId = (string)$USER->GetParam('TIME_ZONE');
			if ($timeZoneId === '' && \CTimeZone::IsAutoTimeZone($USER->GetParam('AUTO_TIME_ZONE')))
			{
				$timeZoneId = \CTimeZone::getTzCookie() ?? '';
			}
		}

		try
		{
			return new DateTimeZone($timeZoneId !== '' ? $timeZoneId : date_default_timezone_get());
		}
		catch (Throwable)
		{
			return new DateTimeZone(date_default_timezone_get());
		}
	}

	private function mapQueryError(Error $error): Error
	{
		return match ($error->getCode()) {
			AuditQueryService::ERROR_ACCESS_DENIED => $this->createError(
				'Access denied.',
				self::ERROR_ACCESS_DENIED,
				403,
			),
			AuditQueryService::ERROR_LICENSE_UNAVAILABLE => $this->createError(
				'Audit license is unavailable.',
				self::ERROR_AUDIT_LICENSE_UNAVAILABLE,
				423,
			),
			AuditQueryService::ERROR_NOT_FOUND => $this->createError(
				'Disk audit event was not found.',
				self::ERROR_NOT_FOUND,
				404,
			),
			default => $this->createError(
				'Disk audit data is temporarily unavailable.',
				self::ERROR_TEMPORARY,
				503,
			),
		};
	}

	private function createError(string $message, string $code, int $httpStatus): Error
	{
		return new Error(
			$message,
			$code,
			['httpStatus' => $httpStatus],
		);
	}
}
