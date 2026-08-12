<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service;

use Bitrix\Vibecodeconnector\Internal\Entity\DeveloperKey;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\DeveloperKeyIssuer;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;

final class DeveloperKeyService
{
	private const DEVELOPER_KEY_TITLE = 'Vibecode Integration';

	private readonly DeveloperKeyIssuer $issuer;

	public function __construct(
		EntryPoint $entryPoint,
		?DeveloperKeyIssuer $issuer = null,
	)
	{
		$this->issuer = $issuer ?? new DeveloperKeyIssuer($entryPoint);
	}

	public function issueFor(int $userId): DeveloperKey
	{
		return $this->issuer->issue($userId, self::DEVELOPER_KEY_TITLE);
	}
}
