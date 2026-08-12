<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Service;

use Bitrix\Rest\Public\Contract\Application\RestApplicationInterface;
use Bitrix\Vibecodeconnector\Internal\Service\DeveloperKeyService;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\ApiKey;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\ApplicationKey;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;

final class ProvisioningService
{
	private function __construct(
		private readonly EntryPoint $entryPoint,
	) {
	}

	public static function forBitrix24(): self
	{
		return new self(EntryPoint::bitrix24());
	}

	public static function forVibecode(?string $serverIss): self
	{
		return new self(EntryPoint::vibecode($serverIss));
	}

	public function issueDeveloperKey(int $userId): string
	{
		return (new DeveloperKeyService($this->entryPoint))->issueFor($userId)->url;
	}

	/**
	 * @param string[] $scopes
	 */
	public function issueApiKey(int $userId, array $scopes, string $title): string
	{
		return (new ApiKey\Issuer($this->entryPoint))->issue($userId, $scopes, $title);
	}

	/**
	 * @param string[] $scopes
	 * @param array<string, string> $menuTitles
	 */
	public function issueApplicationKey(
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
		return (new ApplicationKey\Issuer($this->entryPoint))->issue(
			$userId,
			$handlerUrl,
			$scopes,
			$title,
			$onlyApi,
			$mobile,
			$installUrl,
			$menuTitles,
			$applicationToken,
		);
	}
}
