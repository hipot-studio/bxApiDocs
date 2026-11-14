<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App;

use Bitrix\Rest\Internal\Access\App\Model\AppModel;

final class AppBatchAccessChecker
{
	private AppAccessController $controller;
	/** @var array<int, string[]> */
	private array $expectedCodes = [];
	/** @var string[] */
	private array $accessCodes = [];
	/** @var array<string, true> */
	private array $userAccessCodes = [];
	/** @var array<string, true> */
	private array $foldedUserAccessCodes = [];
	private bool $loaded = false;

	/**
	 * @param array<int, array{ID: int, ACCESS_CODES: string[]}> $apps
	 */
	public function __construct(
		private readonly int $userId,
		array $apps,
	)
	{
		$this->controller = AppAccessController::getInstance($this->userId);
		foreach ($apps as $app)
		{
			$this->expectedCodes[(int)$app['ID']] = $app['ACCESS_CODES'];
			foreach ($app['ACCESS_CODES'] as $accessCode)
			{
				$accessCode = trim($accessCode);
				if ($accessCode !== '')
				{
					$this->accessCodes[] = $accessCode;
				}
			}
		}

		$this->accessCodes = array_values(array_unique($this->accessCodes));
	}

	public function canAccess(AppModel $app): bool
	{
		return $this->controller->check(
			AppAction::UseApp,
			$app,
			['batchAccessChecker' => $this],
		);
	}

	public function canAccessBeforeInstallationCompleted(AppModel $app): bool
	{
		return $this->controller->check(
			AppAction::UseApp,
			$app,
			[
				'skipInstallCheck' => true,
				'batchAccessChecker' => $this,
			],
		);
	}

	/**
	 * Returns null when the regular access check must be used.
	 *
	 * @param string[] $accessCodes
	 */
	public function tryResolveAccess(AppModel $app, array $accessCodes): ?bool
	{
		$appId = $app->getId();
		if (!array_key_exists($appId, $this->expectedCodes))
		{
			return null;
		}

		$expectedCodes = $this->expectedCodes[$appId];
		if ($accessCodes !== $expectedCodes)
		{
			return null;
		}

		if (in_array('G2', $accessCodes, true))
		{
			return true;
		}

		if ($this->userId > 0 && (in_array('AU', $accessCodes, true) || in_array('UA', $accessCodes, true)))
		{
			return true;
		}

		if ($this->userId === 0 && in_array('UA', $accessCodes, true))
		{
			return null;
		}

		$this->load();
		foreach ($accessCodes as $accessCode)
		{
			if (isset($this->userAccessCodes[trim($accessCode)]))
			{
				return true;
			}
		}
		foreach ($accessCodes as $accessCode)
		{
			if (isset($this->foldedUserAccessCodes[$this->fold($accessCode)]))
			{
				return null;
			}
		}

		return false;
	}

	private function load(): void
	{
		if ($this->loaded)
		{
			return;
		}

		$this->loaded = true;
		if (empty($this->accessCodes))
		{
			return;
		}

		$rows = \CAccess::GetUserCodes($this->userId, ['ACCESS_CODE' => $this->accessCodes]);
		while ($row = $rows->Fetch())
		{
			$accessCode = trim((string)$row['ACCESS_CODE']);
			$this->userAccessCodes[$accessCode] = true;
			$this->foldedUserAccessCodes[$this->fold($accessCode)] = true;
		}
	}

	private function fold(string $accessCode): string
	{
		return strtolower(trim($accessCode));
	}
}
