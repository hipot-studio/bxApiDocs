<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Command\Application\ForceInstallPersonalAppCommand;
use Bitrix\Rest\Public\Command\Application\InstallPersonalAppCommand;
use Bitrix\Rest\Public\Contract\Application\RestApplicationInterface;
use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;

final class PersonalApplicationInstaller
{
	private const ATTRIBUTES = ['vibe24' => 'Y', 'vibecodeconnector' => 'Y'];

	public function __construct(
		private readonly EntryPoint $entryPoint,
	) {
		Loader::includeModule('rest');
	}

	/**
	 * @param string[] $scopes
	 * @param array<string, string> $menuTitles
	 */
	public function install(
		int $userId,
		string $handlerUrl,
		array $scopes,
		string $title,
		bool $onlyApi = true,
		bool $mobile = false,
		string $installUrl = '',
		array $menuTitles = [],
		?string $applicationToken = null,
	): RestApplicationInterface
	{
		return $this->dispatch(new InstallPersonalAppCommand(
			userId: $userId,
			appClientId: null,
			title: $title,
			scopes: $scopes,
			handlerUrl: $handlerUrl,
			installUrl: $installUrl,
			onlyApi: $onlyApi,
			mobile: $mobile,
			menuTitles: $menuTitles,
			attributes: $this->buildAttributes(),
			applicationToken: $applicationToken,
		));
	}

	/**
	 * @param string[] $scopes
	 * @param array<string, string> $menuTitles
	 */
	public function forceInstall(
		int $userId,
		string $handlerUrl,
		array $scopes,
		string $title,
		bool $onlyApi = true,
		bool $mobile = false,
		string $installUrl = '',
		array $menuTitles = [],
		?string $applicationToken = null,
	): RestApplicationInterface
	{
		return $this->dispatch(new ForceInstallPersonalAppCommand(
			userId: $userId,
			appClientId: null,
			title: $title,
			scopes: $scopes,
			handlerUrl: $handlerUrl,
			installUrl: $installUrl,
			onlyApi: $onlyApi,
			mobile: $mobile,
			menuTitles: $menuTitles,
			attributes: $this->buildAttributes(),
			applicationToken: $applicationToken,
		));
	}

	/**
	 * @return array<string, string>
	 */
	private function buildAttributes(): array
	{
		return array_merge(self::ATTRIBUTES, $this->entryPoint->toAttributes());
	}

	private function dispatch(AbstractCommand $command): RestApplicationInterface
	{
		$result = $command->run();

		if (!$result->isSuccess())
		{
			$errorCode = $result->getError()?->getCode();

			throw new ProvisioningFailedException(
				'Application key install failed: ' . implode('; ', $result->getErrorMessages()),
				$errorCode === null ? 'APP_INSTALL_FAILED' : (string)$errorCode,
			);
		}

		$app = reset($result->getData());

		if (($app->getClientSecret() ?? '') === '')
		{
			throw new ProvisioningFailedException(
				'Personal application installed but client secret is empty; secret retrieval path must be verified on a real stand',
				'APP_SECRET_MISSING',
			);
		}

		return $app;
	}
}
