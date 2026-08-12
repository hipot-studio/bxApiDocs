<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Command\IncomingWebhook\CreateIncomingWebhookCommand;
use Bitrix\Rest\Public\Command\IncomingWebhook\ForceCreateIncomingWebhookCommand;
use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;

final class IncomingWebhookCreator
{
	private const ATTRIBUTES = ['vibe24' => 'Y', 'vibecodeconnector' => 'Y'];

	public function __construct(
		private readonly EntryPoint $entryPoint,
	) {
		Loader::includeModule('rest');
	}

	/**
	 * @param string[] $scopes
	 */
	public function create(int $userId, array $scopes, string $title): string
	{
		return $this->dispatch(new CreateIncomingWebhookCommand(
			userId: $userId,
			title: $title,
			scopes: $scopes,
			attributes: $this->buildAttributes(),
		));
	}

	/**
	 * @param string[] $scopes
	 */
	public function forceCreate(int $userId, array $scopes, string $title): string
	{
		return $this->dispatch(new ForceCreateIncomingWebhookCommand(
			userId: $userId,
			title: $title,
			scopes: $scopes,
			attributes: $this->buildAttributes(),
		));
	}

	/**
	 * @return array<string, string>
	 */
	private function buildAttributes(): array
	{
		return array_merge(self::ATTRIBUTES, $this->entryPoint->toAttributes());
	}

	private function dispatch(AbstractCommand $command): string
	{
		$result = $command->run();

		if (!$result->isSuccess())
		{
			$errorCode = $result->getError()?->getCode();

			throw new ProvisioningFailedException(
				'Incoming webhook creation failed: ' . implode('; ', $result->getErrorMessages()),
				$errorCode === null ? 'WEBHOOK_CREATE_FAILED' : (string)$errorCode,
			);
		}

		$webhook = reset($result->getData());
		$url = \CRestUtil::getWebhookEndpoint($webhook->getPassword(), $webhook->getUserId());

		return $url;
	}
}
