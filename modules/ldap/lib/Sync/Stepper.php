<?php

namespace Bitrix\Ldap\Sync;

use Bitrix\Ldap\DI\Container;
use Bitrix\Main;
use \CLDAP;

/**
 * This API is experimental and can be changed in further updates.
 * @psalm-suppress MissingConstructor
 */
class Stepper extends Main\Update\Stepper
{
	protected Container $container;

	protected Logger $logger;

	protected CLDAP $connection;

	protected int $serverId;

	protected int $sessionId;

	protected Session $session;

	protected SessionManager $sessionManager;

	/** @var string[] */
	protected array $baseDnList;

	protected static $moduleId = 'ldap';

	/**
	 * @param array $option
	 * @return bool
	 */
	public function execute(array &$option): bool
	{
		$result = $this->init();
		if (!$result->isSuccess())
		{
			return $this->failure($result->getError()?->getMessage() ?? 'Cannot initialize ldap import stepper');
		}

		return match ($this->session->state)
		{
			State::Idle => $this->handleFirstLaunch(),
			State::Import => $this->handleImportStep(),
			State::Deactivate => $this->handleDeactivateStep(),
			State::Finished => $this->succeed(),
			State::Failure => self::FINISH_EXECUTION,
		};
	}

	protected function init(): Main\Result
	{
		$result = new Main\Result();

		$this->container = Container::getInstance();

		$this->logger = $this->container->getSyncLogger();
		$this->logger->start('LdapSyncStepper');

		$this->sessionManager = $this->container->getSyncSessionManager();

		$this->sessionId = (int)($this->outerParams[0] ?? 0);

		if ($this->sessionId <= 0)
		{
			return $result->addError(new Main\Error('Session id not specified'));
		}

		$session = $this->sessionManager->getSessionById($this->sessionId);
		if (!$session)
		{
			return $result->addError(new Main\Error('Session not found'));
		}

		$this->session = $session;

		$this->serverId = $session->serverId;

		$connection = $this->getConnection($this->serverId);
		if (!$connection)
		{
			return $result->addError(new Main\Error('Cannot connect to LDAP server'));
		}

		$this->connection = $connection;

		$this->baseDnList = $this->connection->getBaseDnList();
		if (empty($this->baseDnList))
		{
			return $result->addError(new Main\Error('No base DNs found for synchronization'));
		}

		return $result;
	}

	protected function handleFirstLaunch(): bool
	{
		$this->logger->collectDebugInfo();

		return $this->processPage(dn: $this->baseDnList[0]);
	}

	protected function handleImportStep(): bool
	{
		$dn = $this->session->dn;
		$cookie = $this->session->cookie;
		$baseDnList = $this->baseDnList;

		if (empty($cookie))
		{
			// processed all users from current DN and moving to next DN
			$dnMap = array_flip($baseDnList);
			$nextKey = isset($dnMap[$dn]) ? $dnMap[$dn] + 1 : null;
			if ($nextKey === null || !isset($baseDnList[$nextKey]))
			{
				// processed all DN's
				$this->logger->log('SyncStepper: all DNs processed, go to deactivation stage');
				$updateStateResult = $this->sessionManager->step($this->sessionId, State::Deactivate, []);

				return $updateStateResult->isSuccess()
					? self::CONTINUE_EXECUTION
					: $this->failure($updateStateResult->getError()?->getMessage() ?? 'Cannot move to deactivation step');
			}

			$dn = $baseDnList[$nextKey];
			$cookie = '';
		}

		return $this->processPage($dn, $cookie);
	}

	protected function processPage(string $dn, string $cookie = ''): bool
	{
		$this->logger->log(sprintf(
			'SyncStepper: start processing page. Dn: %s, cookie: %s',
			$dn,
			empty($cookie) ? 'none' : 'filled'
		));

		$importStep = $this->container->getSyncImportStep();
		$importStep->execute($this->connection, $this->session, $dn, $cookie);

		$this->logger->log(sprintf(
			'SyncStepper: Page processed, added %s users, updated %s users, last error: %s',
			$importStep->getAddedCount(),
			$importStep->getUpdatedCount(),
			$importStep->getLastError() ?? 'none',
		));

		$updateStateResult = $this->sessionManager->step(
			$this->sessionId,
			State::Import,
			[ 'DN' => $dn, 'COOKIE' => base64_encode($importStep->getCookie()) ],
		);

		return $updateStateResult->isSuccess()
			? self::CONTINUE_EXECUTION
			: $this->failure($updateStateResult->getError()?->getMessage() ?? 'Cannot move to import step');
	}

	protected function handleDeactivateStep(): bool
	{
		$this->logger->log('SyncStepper: deactivation step start');
		$deactivationStep = $this->container->getSyncDeactivationStep();
		$deactivationStep->execute($this->session);

		$candidatesCount = $deactivationStep->getCandidatesCount();
		$deactivatedCount = $deactivationStep->getDeactivatedCount();

		if ($candidatesCount > 0)
		{
			$this->logger->log(sprintf(
				'SyncStepper: deactivation in progress. Found: %s, deactivated: %s',
				$candidatesCount,
				$deactivatedCount,
			));

			return self::CONTINUE_EXECUTION;
		}

		$this->logger->log('SyncStepper: deactivation completed, finish session');

		return $this->succeed();
	}

	protected function getConnection(int $serverId): CLDAP|false
	{
		/** @var CLDAP|false $server */

		$server = \CLdapServer::GetList([], ['ID' => $serverId, 'ACTIVE' => 'Y'])->GetNextServer();
		if (!$server)
		{
			$this->logger->log("SyncStepper: LDAP server {$serverId} not found or deactivated");
			return false;
		}

		if (!$server->Connect())
		{
			$this->logger->log("SyncStepper: Cannot connect to LDAP server {$serverId}");
			return false;
		}

		if (!$server->BindAdmin())
		{
			$this->logger->log("SyncStepper: server {$serverId} cannot bind admin");
			$server->Disconnect();
			return false;
		}

		return $server;
	}

	protected function succeed(): bool
	{
		$this->sessionManager->finish($this->sessionId);

		$this->logger->stop();

		return self::FINISH_EXECUTION;
	}

	protected function failure(string $reason = ''): bool
	{
		if ($reason !== '')
		{
			$this->logger->log('SyncStepper failure: ' . $reason);
		}

		if ($this->sessionId > 0)
		{
			$this->sessionManager->failure($this->sessionId, $reason);
		}

		$this->logger->stop();

		return self::FINISH_EXECUTION;
	}
}