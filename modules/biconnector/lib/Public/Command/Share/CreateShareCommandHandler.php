<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;
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
	private SupersetDashboardShareRepository $repository;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get('biconnector.repository.share');
	}

	public function __invoke(CreateShareCommand $command): CreateShareResult
	{
		$result = new CreateShareResult();

		$externalFilterValuesJson = $command->externalFilterValues !== null
			? Json::encode($command->externalFilterValues)
			: null
		;

		$urlParameterValuesJson = $command->urlParameterValues !== null
			? Json::encode($command->urlParameterValues)
			: null
		;

		try
		{
			$existingShare = $this->repository->getByDashboardAndUser(
				$command->dashboardId,
				$command->createdById,
			);

			if ($existingShare)
			{
				$wasInactive = !$existingShare->isActive();
				$oldToken = $existingShare->getToken();
				$passwordChanged = $existingShare->getPassword() !== $command->password;

				$existingShare
					->setPassword($command->password)
					->setDateExpire($command->dateExpire)
					->setActive('Y')
					->setDateModify(new DateTime())
				;

				if ($wasInactive)
				{
					$existingShare->setToken(SupersetDashboardShareTable::generateToken());
					SharePullService::sendRevokeEvent($oldToken);
				}
				elseif ($passwordChanged)
				{
					SharePullService::sendRevokeEvent($oldToken);
				}

				if ($command->externalFilterValues !== null)
				{
					$existingShare->setExternalFilterValues($externalFilterValuesJson);
				}

				if ($command->urlParameterValues !== null)
				{
					$existingShare->setUrlParameterValues($urlParameterValuesJson);
				}

				$this->repository->save($existingShare);

				ShareExpireAgent::add(
					$existingShare->getId(),
					$existingShare->getToken(),
					$command->dateExpire,
				);

				$result->setShare($existingShare);

				return $result;
			}

			$share = new SupersetDashboardShare(
				$command->dashboardId,
				SupersetDashboardShareTable::generateToken(),
				$command->password,
				$command->dateExpire,
				'Y',
				$command->createdById,
				new DateTime(),
				new DateTime(),
				$externalFilterValuesJson,
				$urlParameterValuesJson,
			);

			$this->repository->save($share);

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
}
