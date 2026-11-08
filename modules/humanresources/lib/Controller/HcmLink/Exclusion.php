<?php

namespace Bitrix\HumanResources\Controller\HcmLink;

use Bitrix\HumanResources\Engine\HcmLinkController;
use Bitrix\HumanResources\Internals\Repository\Query\RealUserFilter;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Service\HcmLink\ExclusionService;
use Bitrix\Intranet;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

/**
 * Public surface of the exclusion list: the mapping screen and the document preparation window.
 * Every action takes the company and answers only about records of that company - the identifier
 * space of exclusions overlaps the one of persons, and telling them apart on the client is a
 * convenience, not a guarantee.
 */
class Exclusion extends HcmLinkController
{
	private const ERROR_COMPANY_NOT_FOUND = 'HR_HCMLINK_INTEGRATION_NOT_FOUND';
	private const ERROR_INVALID_USER_IDS = 'INVALID_USER_IDS';
	private const ERROR_OPERATION_FAILED = 'EXCLUSION_FAILED';

	public function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON]),
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\Csrf(),
			new Main\Engine\ActionFilter\HttpMethod(
				[Main\Engine\ActionFilter\HttpMethod::METHOD_POST],
			),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	/**
	 * @param array<mixed> $userIds identifiers as they came from the client
	 *
	 * @return array{addedCount: int, excludedUserIds: array<int>}|null null when the request is refused
	 */
	public function addAction(int $companyId, array $userIds): ?array
	{
		if (
			!$this->checkAccess()
			|| !$this->checkSignSupportsExclusions()
			|| !$this->checkCompanyExists($companyId)
		)
		{
			return null;
		}

		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === null)
		{
			return null;
		}

		// An exclusion is a row of its own, and the set comes from the client: an identifier of nobody
		// would create a record no interface shows and nobody can remove.
		$userIds = RealUserFilter::filterRealUserIds($userIds);
		if ($userIds === [])
		{
			$this->addError($this->makeInvalidUserIdsError());

			return null;
		}

		try
		{
			$result = Container::getHcmLinkExclusionService()->exclude($companyId, $userIds, $this->getActorId());
		}
		catch (\Throwable $exception)
		{
			$this->reportOperationFailure($exception);

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addServiceErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();

		return [
			'addedCount' => (int)$data['addedCount'],
			'excludedUserIds' => $data['excludedUserIds'],
		];
	}

	/**
	 * @param array<mixed> $userIds identifiers as they came from the client
	 *
	 * @return array{deletedCount: int}|null null when the request is refused
	 */
	public function deleteAction(int $companyId, array $userIds): ?array
	{
		if (!$this->checkAccess() || !$this->checkCompanyExists($companyId))
		{
			return null;
		}

		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === null)
		{
			return null;
		}

		// Portal membership is not required here on purpose: an exclusion of a user already removed from
		// the portal stays on the list and has to remain removable. The company scope of the records is
		// kept by the service, which deletes by the pair "company + user".
		try
		{
			$result = Container::getHcmLinkExclusionService()
				->removeExclusions($companyId, $userIds, $this->getActorId())
			;
		}
		catch (\Throwable $exception)
		{
			$this->reportOperationFailure($exception);

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addServiceErrors($result->getErrors());

			return null;
		}

		return ['deletedCount' => (int)$result->getData()['deletedCount']];
	}

	/**
	 * @param array<mixed>|null $userIds both lists are answered for exactly this set
	 *
	 * @return array{excludedUserIds: array<int>, mappedUserIds: array<int>, canManage: bool}|null
	 */
	public function listAction(int $companyId, ?array $userIds = null): ?array
	{
		if (!$this->checkAccess() || !$this->checkCompanyExists($companyId))
		{
			return null;
		}

		$excludedUserIds = [];
		$mappedUserIds = [];

		// Without a set of users the answer stays a capability probe: the whole exclusion list of the
		// company is the paginated source of the grid, not of this action.
		if ($userIds !== null && $userIds !== [])
		{
			$userIds = $this->normalizeUserIds($userIds);
			if ($userIds === null)
			{
				return null;
			}

			$excludedUserIds = Container::getHcmLinkExclusionService()
				->filterExcludedUserIds($companyId, $userIds)
			;

			// The confirmation of excluding a mapped user has nowhere else to learn this from. The
			// mapping is asked about the passed set: the whole mapping of the company is tens of
			// thousands of identifiers on a large portal, and the action is called before every
			// addition.
			$mappedUserIds = Container::getHcmLinkPersonRepository()
				->filterMappedUserIds($companyId, $userIds)
			;
		}

		return [
			'excludedUserIds' => $excludedUserIds,
			'mappedUserIds' => $mappedUserIds,
			// The permission level is named on its own and may stop matching the one this action is
			// guarded by. The interface draws the button disabled instead of hiding it.
			'canManage' => Container::getHcmLinkAccessService()->canManageExclusions(),
		];
	}

	private function checkAccess(): bool
	{
		if (!Container::getHcmLinkAccessService()->canManageExclusions())
		{
			$this->addError($this->makeAccessDeniedError());

			return false;
		}

		return true;
	}

	/**
	 * Creating an exclusion is the only operation the release state of sign gates: an exclusion drops
	 * the person link, and a sign that knows nothing about exclusions would start blocking a member
	 * that used to be sent fine. Removing and reading what is already stored stay available whatever
	 * the installed sign is. The refusal reuses the access denied answer: while the feature is not
	 * released the interface draws no action at all, so there is nothing to explain to the user.
	 */
	private function checkSignSupportsExclusions(): bool
	{
		if (!Container::getHcmLinkExclusionService()->isWriteSupportedBySign())
		{
			$this->addError($this->makeAccessDeniedError());

			return false;
		}

		return true;
	}

	private function checkCompanyExists(int $companyId): bool
	{
		if (Container::getHcmLinkCompanyRepository()->getById($companyId) === null)
		{
			$this->addError(new Main\Error(
				Loc::getMessage('HUMANRESOURCES_HCMLINK_EXCLUSION_COMPANY_NOT_FOUND'),
				self::ERROR_COMPANY_NOT_FOUND,
			));

			return false;
		}

		return true;
	}

	/**
	 * The set is refused as a whole instead of being silently trimmed: a mass operation applied to a
	 * part of the selection is harder to notice than a refused one.
	 *
	 * @param array<mixed> $userIds
	 *
	 * @return array<int>|null unique positive identifiers, null when an error is already reported
	 */
	private function normalizeUserIds(array $userIds): ?array
	{
		// The size is bounded before the set reaches any query, and on the raw set - the same way the
		// service bounds it.
		if (count($userIds) > ExclusionService::USER_IDS_LIMIT)
		{
			$this->addError($this->makeLimitExceededError());

			return null;
		}

		$normalized = [];
		foreach ($userIds as $userId)
		{
			if (!is_int($userId) && !(is_string($userId) && preg_match('/^[0-9]+$/D', $userId) === 1))
			{
				$this->addError($this->makeInvalidUserIdsError());

				return null;
			}

			$userId = (int)$userId;
			if ($userId <= 0)
			{
				$this->addError($this->makeInvalidUserIdsError());

				return null;
			}

			$normalized[$userId] = $userId;
		}

		if ($normalized === [])
		{
			$this->addError($this->makeInvalidUserIdsError());

			return null;
		}

		return array_values($normalized);
	}

	/**
	 * The service reports in its own language; the client keeps the code and gets a localized text.
	 *
	 * @param Main\Error[] $errors
	 */
	private function addServiceErrors(array $errors): void
	{
		foreach ($errors as $error)
		{
			$this->addError(match ($error->getCode())
			{
				ExclusionService::ERROR_LIMIT_EXCEEDED => $this->makeLimitExceededError(),
				default => new Main\Error(
					Loc::getMessage('HUMANRESOURCES_HCMLINK_EXCLUSION_FAILED'),
					$error->getCode() ?: self::ERROR_OPERATION_FAILED,
				),
			});
		}
	}

	private function reportOperationFailure(\Throwable $exception): void
	{
		// A storage failure carries the failed SQL in its message. It belongs in the log, not in the
		// response: the client gets a stable code and a localized text.
		Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);

		$this->addError(new Main\Error(
			Loc::getMessage('HUMANRESOURCES_HCMLINK_EXCLUSION_FAILED'),
			self::ERROR_OPERATION_FAILED,
		));
	}

	private function makeLimitExceededError(): Main\Error
	{
		return new Main\Error(
			Loc::getMessage(
				'HUMANRESOURCES_HCMLINK_EXCLUSION_LIMIT_EXCEEDED',
				['#LIMIT#' => ExclusionService::USER_IDS_LIMIT],
			),
			ExclusionService::ERROR_LIMIT_EXCEEDED,
		);
	}

	private function makeInvalidUserIdsError(): Main\Error
	{
		return new Main\Error(
			Loc::getMessage('HUMANRESOURCES_HCMLINK_EXCLUSION_INVALID_USER_IDS'),
			self::ERROR_INVALID_USER_IDS,
		);
	}

	private function getActorId(): int
	{
		return (int)Main\Engine\CurrentUser::get()->getId();
	}
}
