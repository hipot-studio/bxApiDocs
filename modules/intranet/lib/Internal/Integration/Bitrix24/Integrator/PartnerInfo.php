<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24\Integrator;

use Bitrix\Bitrix24\Public\Command\Partner\AddIntegratorInfoCommand;
use Bitrix\Bitrix24\Public\Provider\User\PartnerInfoProvider;
use Bitrix\Bitrix24\Internal\Entity\User\PartnerInfo as PartnerInfoEntity;
use Bitrix\Bitrix24\Internal\Entity\User\PartnerInfoCollection;
use Bitrix\Main\Loader;

class PartnerInfo
{
	private bool $isAvailable;
	private ?PartnerInfoProvider $provider;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('bitrix24');
		$this->provider = $this->isAvailable ? new PartnerInfoProvider() : null;
	}

	public function getList(): ?PartnerInfoCollection
	{
		return $this->provider?->getList();
	}

	public function getByUserId(int $userId): ?PartnerInfoEntity
	{
		return $this->provider?->getByUserId($userId);
	}

	public function addByResponseAndUserId(array $response, int $userId): void
	{
		if (!$this->isAvailable)
		{
			return;
		}

		$command = (new AddIntegratorInfoCommand($response, $userId));
		$command->run();
	}
}
