<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorInterface;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;

/**
 * Transport to the reports instance, resolved per call so the run speaks
 * through the mode (cloud or self-hosted) the portal is in at that moment.
 * Left open for extension so unit tests can replace it with a stub.
 */
class DigestIntegratorProvider
{
	public function getIntegrator(): IntegratorInterface
	{
		return IntegratorFactory::getInstance();
	}

	/**
	 * The run is unattended, so the user of the process is not the recipient: a request made on behalf
	 * of the recipient names them explicitly.
	 *
	 * @return Dto\User|null Null when the portal has no such real user.
	 */
	public function findUser(int $userId): ?Dto\User
	{
		return (new SupersetUserRepository())->getById($userId);
	}
}
