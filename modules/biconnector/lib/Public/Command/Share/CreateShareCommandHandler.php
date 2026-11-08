<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Superset\Dashboard\ShareExpireAgent;
use Bitrix\BIConnector\Superset\Dashboard\SharePullService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

class CreateShareCommandHandler
{
	public const ERROR_SHARE_FORBIDDEN = 'share_forbidden';
	public const ERROR_SHARE_DISABLED_BY_ADMIN = 'share_disabled_by_admin';

	private SupersetDashboardShareRepository $repository;
	private SupersetDashboardShareBanRepository $banRepository;

	public function __construct()
	{
		$serviceLocator = ServiceLocator::getInstance();
		$this->repository = $serviceLocator->get('biconnector.repository.share');
		$this->banRepository = $serviceLocator->get('biconnector.repository.shareBan');
	}

	public function __invoke(CreateShareCommand $command): CreateShareResult
	{
		$result = new CreateShareResult();

		try
		{
			if ($this->banRepository->isBanned($command->dashboardId))
			{
				return $result->addError($this->makeForbiddenError());
			}

			$share = $this->saveShare($command);
			if ($share === null)
			{
				return $result->addError(new Error(
					'The public link was switched off by an administrator',
					self::ERROR_SHARE_DISABLED_BY_ADMIN,
				));
			}

			// A ban committed between the check above and the save must not leave the link working.
			if ($this->banRepository->isBannedForUpdate($command->dashboardId))
			{
				$this->repository->deactivateIfActive($share->getId(), null, new DateTime());
				ShareExpireAgent::remove($share->getId());
				SharePullService::sendRevokeEvent($share->getToken());

				return $result->addError($this->makeForbiddenError());
			}

			ShareExpireAgent::add(
				$share->getId(),
				$share->getToken(),
				$command->dateExpire,
			);

			$result->setShare($share);
		}
		catch (PersistenceException|\Exception $e)
		{
			$result->addError(new Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}

	/**
	 * @return SupersetDashboardShare|null Null when an autosave meets a link switched off by an administrator.
	 */
	private function saveShare(CreateShareCommand $command): ?SupersetDashboardShare
	{
		$externalFilterValuesJson = $command->externalFilterValues !== null
			? Json::encode($command->externalFilterValues)
			: null
		;

		$urlParameterValuesJson = $command->urlParameterValues !== null
			? Json::encode($command->urlParameterValues)
			: null
		;

		$now = new DateTime();

		$share = $this->repository->getByDashboardAndUser(
			$command->dashboardId,
			$command->createdById,
		);

		if (!$share)
		{
			$share = new SupersetDashboardShare(
				$command->dashboardId,
				SupersetDashboardShareTable::generateToken(),
				$command->password,
				$command->dateExpire,
				'Y',
				$command->createdById,
				$now,
				$now,
				$externalFilterValuesJson,
				$urlParameterValuesJson,
			);

			$this->repository->save($share);

			return $share;
		}

		if ($this->isKeptOffByAdmin($share, $command))
		{
			return null;
		}

		if ($share->isValid())
		{
			$token = $share->getToken();
			$isPasswordChanged = $share->getPassword() !== $command->password;
			$this->applyCommand($share, $command, $now, $externalFilterValuesJson, $urlParameterValuesJson);

			if ($this->repository->updateIfActive($share, $token))
			{
				if ($isPasswordChanged)
				{
					SharePullService::sendRevokeEvent($token);
				}

				return $share;
			}

			// Switched off after it was read: turning it on again is a new link, not an edit of the old one.
			$share = $this->repository->getById($share->getId());
			if (!$share instanceof SupersetDashboardShare)
			{
				throw new PersistenceException('Dashboard share disappeared while being saved');
			}

			if ($this->isKeptOffByAdmin($share, $command))
			{
				return null;
			}
		}

		$oldToken = $share->getToken();
		$share->enable(SupersetDashboardShareTable::generateToken(), $now);
		$this->applyCommand($share, $command, $now, $externalFilterValuesJson, $urlParameterValuesJson);
		$this->repository->save($share);
		$this->repository->clearLastOpened($share->getId());
		SharePullService::sendRevokeEvent($oldToken);

		return $share;
	}

	private function isKeptOffByAdmin(SupersetDashboardShare $share, CreateShareCommand $command): bool
	{
		return $command->isAutoSave && $share->isDisabledByAdmin();
	}

	private function applyCommand(
		SupersetDashboardShare $share,
		CreateShareCommand $command,
		DateTime $now,
		?string $externalFilterValuesJson,
		?string $urlParameterValuesJson,
	): void
	{
		$share
			->setPassword($command->password)
			->setDateExpire($command->dateExpire)
			->setDateModify($now)
		;

		if ($externalFilterValuesJson !== null)
		{
			$share->setExternalFilterValues($externalFilterValuesJson);
		}

		if ($urlParameterValuesJson !== null)
		{
			$share->setUrlParameterValues($urlParameterValuesJson);
		}
	}

	private function makeForbiddenError(): Error
	{
		return new Error('Public links to this dashboard are forbidden', self::ERROR_SHARE_FORBIDDEN);
	}
}
