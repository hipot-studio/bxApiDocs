<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Public\Command\Licensing\VibePlus\ActivateVibeDemoCommand;
use Bitrix\Bitrix24\Public\Command\Licensing\VibePlus\ResetVibeDemoCommand;
use Bitrix\Bitrix24\Public\Provider\VibePlus\VibeDemoStateProvider;
use Bitrix\Main;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

class VibeDemoGateway
{
	private bool $isModuleIncluded;

	public function __construct(?bool $isModuleIncluded = null)
	{
		$this->isModuleIncluded = $isModuleIncluded ?? Loader::includeModule('bitrix24');
	}

	public function getState(): Main\Result
	{
		if (!$this->isModuleIncluded)
		{
			return (new Main\Result())->setData([
				'activated' => false,
				'activatedAt' => null,
				'expireDate' => null,
			]);
		}

		return (new VibeDemoStateProvider())->getState();
	}

	public function activate(?int $days): Main\Result
	{
		return $this->isModuleIncluded
			? self::runCommand(new ActivateVibeDemoCommand($days), 'VIBE_DEMO_ACTIVATION_FAILED')
			: self::unavailableResult()
		;
	}

	public function reset(): Main\Result
	{
		return $this->isModuleIncluded
			? self::runCommand(new ResetVibeDemoCommand(), 'VIBE_DEMO_RESET_FAILED')
			: self::unavailableResult()
		;
	}

	private static function runCommand(Main\Command\CommandInterface $command, string $failureCode): Main\Result
	{
		try
		{
			return $command->run();
		}
		catch (CommandException $exception)
		{
			return (new Main\Result())->addError(new Error($exception->getMessage(), $failureCode));
		}
	}

	private static function unavailableResult(): Main\Result
	{
		return (new Main\Result())->addError(new Error(
			'Vibe demo activation is available on cloud portals only',
			'VIBE_DEMO_NOT_CLOUD_PORTAL',
		));
	}
}
