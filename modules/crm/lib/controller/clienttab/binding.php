<?php

namespace Bitrix\Crm\Controller\ClientTab;

use Bitrix\Crm\Binding\OwnerBindingFilter;
use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\AbstractClientBindingCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\BindCompaniesCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\BindContactsCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\SetPrimaryCompanyCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\SetPrimaryContactCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Collection;

/**
 * Binding and the primary item of the contacts and companies tabs of a card.
 * A thin wrapper over the batch client binding commands: one request is one command and one save of the owner.
 */
final class Binding extends Base
{
	public const MAX_IDS = AbstractClientBindingCommand::MAX_IDS;

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new ActionFilter\Scope(ActionFilter\Scope::AJAX);

		return $filters;
	}

	/**
	 * crm.api.clienttab.binding.bind
	 * New items go last in the given order, the primary one does not change.
	 * Up to MAX_IDS ids: a bigger choice is sent by the client in sequential requests,
	 * so a request never runs several saves of the owner with automation.
	 */
	public function bindAction(int $ownerEntityTypeId, int $ownerEntityId, int $childEntityTypeId, array $ids): ?array
	{
		$owner = $this->prepareOwner($ownerEntityTypeId, $ownerEntityId, $childEntityTypeId);
		if ($owner === null)
		{
			return null;
		}

		Collection::normalizeArrayValuesByInt($ids, false);
		$ids = array_values(array_unique($ids));
		if (empty($ids))
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('ids'));

			return null;
		}
		if (count($ids) > self::MAX_IDS)
		{
			$this->addError(new Error('Too many ids, the limit is ' . self::MAX_IDS, ErrorCode::INVALID_ARG_VALUE));

			return null;
		}

		$userId = $this->getCurrentUserId();
		$command = $childEntityTypeId === \CCrmOwnerType::Contact
			? new BindContactsCommand($owner, $ids, $userId)
			: new BindCompaniesCommand($owner, $ids, $userId)
		;
		$result = $command->run();
		$this->addErrors($this->assignErrorsToIds($result, $ids));

		$data = $result->getData();

		return [
			'boundIds' => array_values($data['processedIds'] ?? []),
			'alreadyBoundIds' => array_values($data['skippedIds'] ?? []),
		];
	}

	/**
	 * crm.api.clienttab.binding.setPrimary
	 * Not supported for the contacts of a company.
	 */
	public function setPrimaryAction(int $ownerEntityTypeId, int $ownerEntityId, int $childEntityTypeId, int $childId): ?array
	{
		$owner = $this->prepareOwner($ownerEntityTypeId, $ownerEntityId, $childEntityTypeId);
		if ($owner === null)
		{
			return null;
		}
		if ($childId <= 0)
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('childId'));

			return null;
		}

		$userId = $this->getCurrentUserId();
		$command = $childEntityTypeId === \CCrmOwnerType::Contact
			? new SetPrimaryContactCommand($owner, $childId, $userId)
			: new SetPrimaryCompanyCommand($owner, $childId, $userId)
		;
		$result = $command->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['primaryId' => $childId];
	}

	private function prepareOwner(int $ownerEntityTypeId, int $ownerEntityId, int $childEntityTypeId): ?ItemId
	{
		if (!OwnerBindingFilter::isSupported($childEntityTypeId, $ownerEntityTypeId))
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError($ownerEntityTypeId));

			return null;
		}
		if ($ownerEntityId <= 0)
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('ownerEntityId'));

			return null;
		}

		$canUpdate = Container::getInstance()
			->getUserPermissions($this->getCurrentUserId())
			->item()
			->canUpdateItemIdentifier(new ItemIdentifier($ownerEntityTypeId, $ownerEntityId))
		;
		if (!$canUpdate)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		return new ItemId(EntityType::fromId($ownerEntityTypeId), $ownerEntityId);
	}

	/**
	 * An error of the whole request is reported for each id of it.
	 *
	 * @param int[] $ids
	 * @return Error[]
	 */
	private function assignErrorsToIds(Result $result, array $ids): array
	{
		$errors = [];
		foreach ($result->getErrors() as $error)
		{
			if (isset($error->getCustomData()['id']))
			{
				$errors[] = $error;

				continue;
			}

			foreach ($ids as $id)
			{
				$errors[] = new Error($error->getMessage(), $error->getCode(), ['id' => $id]);
			}
		}

		return $errors;
	}

	private function getCurrentUserId(): int
	{
		return Container::getInstance()->getContext()->getUserId();
	}
}
