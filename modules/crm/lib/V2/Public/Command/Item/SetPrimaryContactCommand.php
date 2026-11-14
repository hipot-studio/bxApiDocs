<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item;

use Bitrix\Crm\V2\Internal\Service\Item\Handler\SetPrimaryContactCommandHandler;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Main\Result;

final class SetPrimaryContactCommand extends AbstractItemCommand
{
	public function __construct(
		private readonly ItemId $itemId,
		private readonly int $contactId,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getItemId(): ItemId
	{
		return $this->itemId;
	}

	public function getContactId(): int
	{
		return $this->contactId;
	}

	protected function execute(): Result
	{
		return (new SetPrimaryContactCommandHandler())->handle($this);
	}
}
