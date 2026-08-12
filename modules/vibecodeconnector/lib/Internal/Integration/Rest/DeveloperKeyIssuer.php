<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Public\Command\IncomingWebhook\CreateSystemIncomingWebhookCommand;
use Bitrix\Rest\Public\Command\IncomingWebhook\DeactivateIncomingWebhookCommand;
use Bitrix\Rest\Public\Provider\IncomingWebhookProvider;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookFilter;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookParams;
use Bitrix\Vibecodeconnector\Internal\Entity\DeveloperKey;
use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;

final class DeveloperKeyIssuer
{
	public const DEVELOPER_KEY_SCOPES = ['rest.developer', 'vibecode.developer'];
	private const DEVELOPER_KEY_ATTRIBUTES = ['vibe24' => 'Y', 'vibecodeconnector' => 'Y', 'developer_key' => 'Y'];
	private const ATTR_DEVELOPER_KEY = 'developer_key';
	private const PREVIOUS_KEYS_LIMIT = 100;

	public function __construct(
		private readonly EntryPoint $entryPoint,
	) {
		Loader::includeModule('rest');
	}

	public function issue(int $userId, string $title): DeveloperKey
	{
		$result = (new CreateSystemIncomingWebhookCommand(
			userId: $userId,
			title: $title,
			scopes: self::DEVELOPER_KEY_SCOPES,
			attributes: $this->buildAttributes(),
		))->run();

		if (!$result->isSuccess())
		{
			throw new ProvisioningFailedException(
				'Developer key issue failed: ' . implode('; ', $result->getErrorMessages()),
				'DEVELOPER_KEY_CREATE_FAILED',
			);
		}

		$webhook = reset($result->getData());
		$newPasswordId = (int)$webhook->getId();

		$this->deactivatePreviousKeys($userId, $newPasswordId);

		return new DeveloperKey(
			userId: $userId,
			url: $this->buildUrl($webhook->getPassword(), $webhook->getUserId()),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function buildAttributes(): array
	{
		return array_merge(self::DEVELOPER_KEY_ATTRIBUTES, $this->entryPoint->toAttributes());
	}

	private function deactivatePreviousKeys(int $userId, int $newPasswordId): void
	{
		$params = new IncomingWebhookParams(
			webhookFilter: new IncomingWebhookFilter(
				userId: $userId,
				attributes: array_merge([self::ATTR_DEVELOPER_KEY => 'Y'], $this->entryPoint->dedupAttributes()),
				type: PasswordType::System,
			),
			pager: new Pager(limit: self::PREVIOUS_KEYS_LIMIT),
		);

		foreach ((new IncomingWebhookProvider())->getList($params) as $webhook)
		{
			if ($webhook->getId() === $newPasswordId || !$webhook->isActive())
			{
				continue;
			}

			(new DeactivateIncomingWebhookCommand(
				userId: $userId,
				passwordId: $webhook->getId(),
			))->run();
		}
	}

	private function buildUrl(string $password, int $userId): string
	{
		$url = \CRestUtil::getWebhookEndpoint($password, $userId);

		return str_replace('rest/', 'rest/api/', $url);
	}
}
