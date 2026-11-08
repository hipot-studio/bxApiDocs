<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Internal\Digest\DigestPeriod;
use Bitrix\BIConnector\Internal\Digest\Dto\DigestSetupPreset;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestNamedLock;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestSystemTemplatePreparer;
use Bitrix\BIConnector\Internal\Digest\Provider\ScopeValueAccessChecker;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Launch of the digest setup wizard from BI: checks the report and the setup availability, reads the presets
 * off the report screen and has the system template copied for the user.
 *
 * The data of the result is the answer for the browser: the state, and the setup data when it is ready.
 * A refused launch keeps its state in the data and carries the reason in the errors.
 */
final class DigestSetupPreparationService
{
	public const ERROR_UNAVAILABLE = 'BICONNECTOR_DIGEST_SETUP_UNAVAILABLE';
	public const ERROR_BUSY = 'BICONNECTOR_DIGEST_SETUP_IN_PROGRESS';

	private const LOCK_PREFIX = 'biconnector.digest-setup.';

	private readonly DigestSetupDashboardGuard $dashboardGuard;
	private readonly DigestSetupScreenPresetReader $screenPresetReader;
	private readonly DigestNamedLock $lock;
	private readonly DigestSystemTemplatePreparer $templatePreparer;

	public function __construct(
		private readonly DigestSetupAvailabilityService $availabilityService,
		?DigestSetupDashboardGuard $dashboardGuard = null,
		?DigestSetupScreenPresetReader $screenPresetReader = null,
		?DigestNamedLock $lock = null,
		?DigestSystemTemplatePreparer $templatePreparer = null,
	)
	{
		$this->dashboardGuard = $dashboardGuard ?? new DigestSetupDashboardGuard();
		$this->screenPresetReader = $screenPresetReader ?? new DigestSetupScreenPresetReader(
			new ScopeValueAccessChecker(
				ServiceLocator::getInstance()->get('biconnector.provider.scopeAccessibleValue'),
			),
		);
		$this->lock = $lock ?? new DigestNamedLock(Application::getConnection());
		$this->templatePreparer = $templatePreparer ?? new DigestSystemTemplatePreparer();
	}

	/**
	 * @param array|null $screenState Native filter id => its state on the report screen, as the browser read it.
	 *     Taken as an intention only: it is read after every check has passed and never decides the setup.
	 */
	public function prepare(int $userId, ?int $dashboardId, ?array $screenState): Result
	{
		$dashboard = $this->dashboardGuard->resolve($dashboardId, $userId);
		if (($dashboardId !== null && $dashboard === null) || $userId <= 0)
		{
			return $this->reject(self::ERROR_UNAVAILABLE, 'Digest setup is unavailable.');
		}

		$availability = $this->availabilityService->check($dashboard, $userId);
		if ($availability->state !== DigestSetupAvailability::READY)
		{
			return (new Result())->setData($availability->toArray());
		}

		$presetValues = $dashboard === null ? [] : ['dashboardId' => $dashboard->getId()];
		$screenPreset = $dashboard === null
			? new DigestSetupPreset()
			: $this->readScreenPreset($dashboard, $screenState, $userId)
		;
		// A wizard opened from BI never starts with an empty period, whatever the screen failed to transfer.
		$presetValues += $screenPreset->withDefaultPeriod(DigestPeriod::CurrentMonth)->toPresetValues();

		// One preparation per user at a time: the client guards a repeated click, the server has to
		// guard a repeated request, because every successful one copies and starts its own template.
		$lockName = self::LOCK_PREFIX . $userId;
		if (!$this->lock->acquire($lockName))
		{
			return $this->reject(self::ERROR_BUSY, 'Digest setup is already running.');
		}

		try
		{
			$prepared = $this->templatePreparer->prepare(
				DigestSetupAvailabilityService::SYSTEM_CODE,
				$userId,
				$presetValues,
			);
		}
		finally
		{
			$this->lock->release($lockName);
		}

		if (!$prepared->isSuccess())
		{
			return (new Result())
				->addErrors($prepared->getErrors())
				->setData(['state' => DigestSetupAvailability::HIDDEN])
			;
		}

		$setupTemplateData = $prepared->getData()['setupTemplateData'] ?? null;
		if (!is_array($setupTemplateData))
		{
			return $this->reject(self::ERROR_UNAVAILABLE, 'Digest setup is unavailable.');
		}

		return (new Result())->setData([
			'state' => DigestSetupAvailability::READY,
			'setupTemplateData' => $setupTemplateData,
		]);
	}

	private function readScreenPreset(Model\Dashboard $dashboard, ?array $screenState, int $userId): DigestSetupPreset
	{
		$acceptedState = $this->dashboardGuard->acceptScreenState($screenState);
		if ($acceptedState === null)
		{
			return new DigestSetupPreset();
		}

		try
		{
			return $this->screenPresetReader->read($dashboard, $acceptedState, $userId);
		}
		catch (\Throwable $exception)
		{
			Logger::logErrors([new Error($exception->getMessage())], ['action' => 'prepareDigestSetup']);

			return new DigestSetupPreset();
		}
	}

	private function reject(string $code, string $message): Result
	{
		return (new Result())
			->addError(new Error($message, $code))
			->setData(['state' => DigestSetupAvailability::HIDDEN])
		;
	}
}
