<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Address;

use Bitrix\Crm\V2\Internal\Service\Address\Handler\DeleteAddressCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier;
use Bitrix\Crm\V2\Public\Entity\Address\AddressOwner;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Result;

final class DeleteAddressCommand implements CommandInterface
{
	public function __construct(
		private readonly AddressIdentifier $identifier,
		private readonly AddressOwner $owner,
		private readonly int $userId,
	)
	{
	}

	public function getIdentifier(): AddressIdentifier
	{
		return $this->identifier;
	}

	public function getOwner(): AddressOwner
	{
		return $this->owner;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function run(): Result
	{
		return (new DeleteAddressCommandHandler())->handle($this);
	}
}
