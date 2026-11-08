<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Superset\Dashboard\ShareExpireAgent;
use Bitrix\BIConnector\Superset\Dashboard\SharePullService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Deactivates every active public link of the author at once: the links keep working under his rights,
 * so they have to be switched off when the account itself goes inactive. A link that fails is reported
 * and does not stop the rest.
 */
class DeactivateUserSharesCommand extends AbstractCommand
{
	public const STEP_SAVE = 'save';
	public const STEP_EXPIRE_AGENT_REMOVE = 'expire-agent-remove';
	public const STEP_REVOKE_EVENT = 'revoke-event';

	public function __construct(
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			$repository = $this->getRepository();
			$shares = $repository->getActiveByCreatedById($this->userId);
		}
		catch (\Exception $e)
		{
			return $result->addError(new Error($e->getMessage(), $e->getCode()));
		}

		$this->revokeSaved(
			$this->deactivate($repository, $shares, $result),
			$result,
		);

		return $result;
	}

	private function getRepository(): SupersetDashboardShareRepository
	{
		return ServiceLocator::getInstance()->get('biconnector.repository.share');
	}

	/**
	 * @param SupersetDashboardShare[] $shares
	 * @return SupersetDashboardShare[] the ones this run switched off
	 */
	private function deactivate(SupersetDashboardShareRepository $repository, array $shares, Result $result): array
	{
		$deactivated = [];
		foreach ($shares as $share)
		{
			try
			{
				// Without a disabler: the link is switched off by the system, not by a person.
				if ($repository->deactivateIfActive($share->getId(), null, new DateTime()))
				{
					$deactivated[] = $share;
				}
			}
			// A failed update leaves the link valid, and the rest of them go on.
			catch (\Exception $e)
			{
				$result->addError($this->makeError($e, $share, self::STEP_SAVE));
			}
		}

		return $deactivated;
	}

	/**
	 * A share whose new state never reached the storage is still valid, so it keeps its expire agent:
	 * only the saved ones go through the side effects. A repeated run will not come back to them
	 * either - they are inactive and out of the selection.
	 *
	 * @param SupersetDashboardShare[] $shares
	 */
	private function revokeSaved(array $shares, Result $result): void
	{
		foreach ($shares as $share)
		{
			try
			{
				ShareExpireAgent::remove($share->getId());
			}
			catch (\Exception $e)
			{
				$result->addError($this->makeError($e, $share, self::STEP_EXPIRE_AGENT_REMOVE));
			}

			try
			{
				SharePullService::sendRevokeEvent($share->getToken());
			}
			catch (\Exception $e)
			{
				$result->addError($this->makeError($e, $share, self::STEP_REVOKE_EVENT));
			}
		}
	}

	/**
	 * The step name tells apart a share that stayed valid because its save failed from a share that
	 * is off but missed a side effect: different consequences, different action on them. The message
	 * of the exception cannot carry that - it comes from the storage and its format is not ours.
	 */
	private function makeError(\Exception $e, SupersetDashboardShare $share, string $step): Error
	{
		return new Error($e->getMessage(), $e->getCode(), [
			'shareId' => $share->getId(),
			'step' => $step,
		]);
	}
}
