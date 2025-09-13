<?php

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\User;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Web\JWT;

class JwtService
{
	private User $user;
	private array $webhookGetParams = [];

	public function __construct(?User $user = null)
	{
		if (!$user)
		{
			/** @var \CUser $USER */
			global $USER;

			$user = User::loadById($USER->getId());
		}
		$this->user = $user;
	}

	public function generateToken(bool $readOnly = false, array $additionalData = []): string
	{
		$secret = Configuration::getJwtSecret();
		$ttl = Configuration::getJwtTtl();
		$oldLeeway = JWT::$leeway;
		JWT::$leeway = $ttl;

		$urlManager = UrlManager::getInstance();
		$avatarUrl = $urlManager->getHostUrl() . $this->user->getAvatarSrc();

		$webhookUrl = Configuration::getWebhookUrl();
		if ($this->webhookGetParams)
		{
			$params = http_build_query($this->webhookGetParams);
			$sign = str_contains($webhookUrl, '?') ? '&' : '?';
			$webhookUrl .= $sign . $params;
		}

		$data = [
			'user_id' => (string)$this->user->getId(),
			'username' => $this->user->getLogin(),
			'avatar_url' => $avatarUrl,
			'access_level' => $readOnly ? 'read' : 'write',
			'can_edit_board' => !$readOnly,
			'webhook_url' => $webhookUrl,
		];

		if ($additionalData)
		{
			$data = array_merge($data, $additionalData);
		}

		$result = JWT::encode($data, $secret);

		JWT::$leeway = $oldLeeway;

		return $result;
	}

	public function addWebhookGetParam(string $key, string $value): self
	{
		$this->webhookGetParams[$key] = $value;
		return $this;
	}

	public function setWebhookGetParams(array $params): self
	{
		$this->webhookGetParams = $params;
		return $this;
	}
}