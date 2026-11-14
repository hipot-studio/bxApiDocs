<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Handler;

use Bitrix\Crm\Attribute\FieldAttributeManager;
use Bitrix\Crm\Binding\OwnerBindingFilter;
use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\EditorAdapter;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\ItemOperationRunner;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\PreparedItemOperation;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\AbstractClientBindingCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\BindCompaniesCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\BindContactsCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\SetPrimaryCompanyCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\SetPrimaryContactCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\UnbindCompaniesCommand;
use Bitrix\Crm\V2\Public\Command\Item\ClientBinding\UnbindContactsCommand;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBinding;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBinding;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * Batch bind, unbind and primary change of contacts or companies of one owner item.
 * One owner update operation and one automation run per command.
 * @internal
 */
final class ClientBindingCommandHandler
{
	public const ERROR_OWNER_NOT_SUPPORTED = 'CRM_CLIENT_BINDING_OWNER_NOT_SUPPORTED';
	public const ERROR_OWNER_NOT_FOUND = 'CRM_CLIENT_BINDING_OWNER_NOT_FOUND';
	public const ERROR_EMPTY_IDS = 'CRM_CLIENT_BINDING_EMPTY_IDS';
	public const ERROR_TOO_MANY_IDS = 'CRM_CLIENT_BINDING_TOO_MANY_IDS';
	public const ERROR_NOT_FOUND = 'CRM_CLIENT_BINDING_NOT_FOUND';
	public const ERROR_NOT_BOUND = 'CRM_CLIENT_BINDING_NOT_BOUND';
	public const ERROR_LIMIT_EXCEEDED = 'CRM_CLIENT_BINDING_LIMIT_EXCEEDED';
	public const ERROR_REQUIRED_LAST = 'CRM_CLIENT_BINDING_REQUIRED_LAST';
	public const ERROR_PRIMARY_NOT_SUPPORTED = 'CRM_CLIENT_BINDING_PRIMARY_NOT_SUPPORTED';

	private const SORT_STEP = 10;

	private readonly ItemOperationRunner $operationRunner;
	private readonly ClientBindingSupport $clientBindingSupport;

	public function __construct(
		?ItemOperationRunner $operationRunner = null,
		?ClientBindingSupport $clientBindingSupport = null,
	)
	{
		$this->operationRunner = $operationRunner ?? new ItemOperationRunner();
		$this->clientBindingSupport = $clientBindingSupport ?? new ClientBindingSupport();
	}

	/**
	 * Result data: processedIds - ids that were changed, skippedIds - ids that already were in the requested state.
	 * Errors of a single id carry it in customData['id'].
	 */
	public function handle(AbstractClientBindingCommand $command): Result
	{
		$ownerId = $command->getOwnerId();
		$childEntityType = $command->getChildEntityType();

		$argumentError = $this->validateArguments($command);
		if ($argumentError !== null)
		{
			return $argumentError;
		}

		$isContacts = $childEntityType->equals(EntityType::contact());
		$kind = $isContacts ? ClientBindingSupport::KIND_CONTACTS : ClientBindingSupport::KIND_COMPANIES;
		$fieldName = $isContacts ? 'contactsId' : 'companiesId';

		$lockResult = $this->clientBindingSupport->lock($ownerId, $fieldName);
		if ($lockResult !== null)
		{
			return $lockResult;
		}

		try
		{
			try
			{
				$item = $this->clientBindingSupport->loadOwner(
					$ownerId,
					$command->getUserId(),
					$kind,
					$command->shouldCheckPermissions(),
				);
			}
			catch (ObjectNotFoundException)
			{
				return $this->createResult([], [], [$this->createError(self::ERROR_OWNER_NOT_FOUND)]);
			}

			$updateCommand = (new UpdateItemCommand($item, $command->getUserId()))
				->setScope($command->getScope())
				->withoutPermissionCheck(!$command->shouldCheckPermissions())
				->withoutRequiredUserFieldsCheck(!$command->shouldCheckRequiredUserFields())
				->withoutAutomation(!$command->shouldRunAutomation())
			;
			$preparedOperation = $this->operationRunner->prepare($updateCommand);
			if ($command->shouldCheckPermissions())
			{
				$accessResult = $preparedOperation->checkAccess();
				if (!$accessResult->isSuccess())
				{
					return $accessResult;
				}

				// The right to update the owner is checked above. The operation would check it again and also demand
				// read access to every bound item, while hidden items may stay bound and be unbound (ERR-052).
				// Read access to the added items is checked by the bind plan.
				$preparedOperation->getOperation()->disableCheckAccess();
			}

			$current = $this->readBindings($item, $isContacts);
			$plan = match (true)
			{
				$command instanceof BindContactsCommand,
				$command instanceof BindCompaniesCommand => $this->planBind($command, $current),
				$command instanceof UnbindContactsCommand,
				$command instanceof UnbindCompaniesCommand => $this->planUnbind($command, $current, $preparedOperation),
				$command instanceof SetPrimaryContactCommand,
				$command instanceof SetPrimaryCompanyCommand => $this->planSetPrimary($command, $current),
				default => null,
			};
			if ($plan === null)
			{
				return $this->createResult([], [], [$this->createError(self::ERROR_OWNER_NOT_SUPPORTED)]);
			}

			if ($plan['bindings'] === null)
			{
				return $this->createResult([], $plan['skipped'], $plan['errors']);
			}

			$this->writeBindings($item, $isContacts, $plan['bindings']);

			$validationResult = $this->operationRunner->validateChangedFields(
				$updateCommand,
				$preparedOperation->getLegacyItem(),
			);
			if (!$validationResult->isSuccess())
			{
				return $validationResult;
			}

			$preparedOperation->syncChangedFieldsFromV2Item();
			$launchResult = $preparedOperation->launchInTransaction();
			if (!$launchResult->isSuccess())
			{
				return $launchResult;
			}

			return $this->createResult($plan['processed'], $plan['skipped'], $plan['errors']);
		}
		finally
		{
			$this->clientBindingSupport->unlock($ownerId, $fieldName);
		}
	}

	private function validateArguments(AbstractClientBindingCommand $command): ?Result
	{
		$ownerEntityType = $command->getOwnerId()->getEntityType();
		if (!OwnerBindingFilter::isSupported($command->getChildEntityType()->getId(), $ownerEntityType->getId()))
		{
			return $this->createResult([], [], [$this->createError(self::ERROR_OWNER_NOT_SUPPORTED)]);
		}

		$ids = $command->getIds();
		if (empty($ids))
		{
			return $this->createResult([], [], [$this->createError(self::ERROR_EMPTY_IDS)]);
		}
		if (count($ids) > AbstractClientBindingCommand::MAX_IDS)
		{
			return $this->createResult([], [], [$this->createError(
				self::ERROR_TOO_MANY_IDS,
				['#MAX#' => AbstractClientBindingCommand::MAX_IDS],
			)]);
		}

		if ($command instanceof SetPrimaryContactCommand && $ownerEntityType->equals(EntityType::company()))
		{
			return $this->createResult([], [], [$this->createError(
				self::ERROR_PRIMARY_NOT_SUPPORTED,
				[],
				$ids[0],
			)]);
		}

		return null;
	}

	/**
	 * @param array<int, array{sort: int, primary: bool}> $current
	 */
	private function planBind(AbstractClientBindingCommand $command, array $current): array
	{
		$skipped = [];
		$candidates = [];
		foreach ($command->getIds() as $id)
		{
			if (isset($current[$id]))
			{
				$skipped[] = $id;
			}
			else
			{
				$candidates[] = $id;
			}
		}

		$errors = [];
		$existing = $this->clientBindingSupport->filterExisting(
			$command->getChildEntityType(),
			$candidates,
			$command->getUserId(),
			$command->shouldCheckPermissions(),
		);
		$existingMap = array_flip($existing);
		$toAdd = [];
		foreach ($candidates as $id)
		{
			if (isset($existingMap[$id]))
			{
				$toAdd[] = $id;
			}
			else
			{
				$errors[] = $this->createError(self::ERROR_NOT_FOUND, [], $id);
			}
		}

		if ($command->getOwnerId()->getEntityType()->equals(EntityType::contact()))
		{
			$available = max(0, ClientBindingSupport::MAX_COMPANIES_FOR_CONTACT - count($current));
			foreach (array_slice($toAdd, $available) as $id)
			{
				$errors[] = $this->createError(
					self::ERROR_LIMIT_EXCEEDED,
					['#MAX#' => ClientBindingSupport::MAX_COMPANIES_FOR_CONTACT],
					$id,
				);
			}
			$toAdd = array_slice($toAdd, 0, $available);
		}

		if (empty($toAdd))
		{
			return ['bindings' => null, 'processed' => [], 'skipped' => $skipped, 'errors' => $errors];
		}

		$bindings = $current;
		$sort = empty($current) ? 0 : max(array_column($current, 'sort'));
		$hasPrimary = in_array(true, array_column($current, 'primary'), true);
		foreach ($toAdd as $id)
		{
			$sort += self::SORT_STEP;
			$bindings[$id] = ['sort' => $sort, 'primary' => !$hasPrimary];
			$hasPrimary = true;
		}

		return ['bindings' => $bindings, 'processed' => $toAdd, 'skipped' => $skipped, 'errors' => $errors];
	}

	/**
	 * @param array<int, array{sort: int, primary: bool}> $current
	 */
	private function planUnbind(
		AbstractClientBindingCommand $command,
		array $current,
		PreparedItemOperation $preparedOperation,
	): array
	{
		$skipped = [];
		$toRemove = [];
		foreach ($command->getIds() as $id)
		{
			if (isset($current[$id]))
			{
				$toRemove[$id] = $id;
			}
			else
			{
				$skipped[] = $id;
			}
		}

		$errors = [];
		if (
			!empty($toRemove)
			&& count($toRemove) === count($current)
			&& $this->isPairFieldEmptyWithoutBindings($preparedOperation->getLegacyItem(), $command)
			&& $this->isPairFieldRequired($preparedOperation, $command)
		)
		{
			$keptId = $this->findPrimaryId($current) ?? array_key_first($current);
			unset($toRemove[$keptId]);
			$errors[] = $this->createError(self::ERROR_REQUIRED_LAST, [], $keptId);
		}

		if (empty($toRemove))
		{
			return ['bindings' => null, 'processed' => [], 'skipped' => $skipped, 'errors' => $errors];
		}

		$bindings = array_diff_key($current, $toRemove);
		if (
			!empty($bindings)
			&& $this->findPrimaryId($bindings) === null
			&& !$this->isPrimaryFlagOnBoundSide($command)
		)
		{
			$bindings[array_key_first($bindings)]['primary'] = true;
		}

		return [
			'bindings' => $bindings,
			'processed' => array_values($toRemove),
			'skipped' => $skipped,
			'errors' => $errors,
		];
	}

	/**
	 * @param array<int, array{sort: int, primary: bool}> $current
	 */
	private function planSetPrimary(AbstractClientBindingCommand $command, array $current): array
	{
		$id = $command->getIds()[0];
		// an item hidden from the user is reported as not bound, not to disclose its existence
		if (
			!isset($current[$id])
			|| (
				$command->shouldCheckPermissions()
				&& empty($this->clientBindingSupport->filterExisting(
					$command->getChildEntityType(),
					[$id],
					$command->getUserId(),
					true,
				))
			)
		)
		{
			return [
				'bindings' => null,
				'processed' => [],
				'skipped' => [],
				'errors' => [$this->createError(self::ERROR_NOT_BOUND, [], $id)],
			];
		}

		if ($current[$id]['primary'])
		{
			return ['bindings' => null, 'processed' => [], 'skipped' => [$id], 'errors' => []];
		}

		$bindings = [];
		foreach ($current as $boundId => $binding)
		{
			$bindings[$boundId] = ['sort' => $binding['sort'], 'primary' => $boundId === $id];
		}

		$firstId = array_key_first($current);
		if ($firstId !== $id)
		{
			$firstSort = $current[$firstId]['sort'];
			if ($firstSort > 0)
			{
				$bindings[$id]['sort'] = $firstSort - 1;
			}
			else
			{
				$sort = self::SORT_STEP;
				$bindings[$id]['sort'] = $sort;
				foreach ($bindings as $boundId => $binding)
				{
					if ($boundId !== $id)
					{
						$sort += self::SORT_STEP;
						$bindings[$boundId]['sort'] = $sort;
					}
				}
			}
		}

		return ['bindings' => $bindings, 'processed' => [$id], 'skipped' => [], 'errors' => []];
	}

	/**
	 * For a company, IS_PRIMARY of a contact binding is the primary company of the contact,
	 * it is maintained by the company Binding itself.
	 */
	private function isPrimaryFlagOnBoundSide(AbstractClientBindingCommand $command): bool
	{
		return $command->getOwnerId()->getEntityType()->equals(EntityType::company());
	}

	/**
	 * Whether the binding field of the pair is empty when all bindings of the command kind are removed.
	 * The client field is also filled by the company of the owner.
	 */
	private function isPairFieldEmptyWithoutBindings(LegacyItem $legacyItem, AbstractClientBindingCommand $command): bool
	{
		if (
			$command->getChildEntityType()->equals(EntityType::contact())
			&& $this->getPairFieldName($command) === EditorAdapter::FIELD_CLIENT
			&& $legacyItem->hasField(LegacyItem::FIELD_NAME_COMPANY_ID)
		)
		{
			return (int)$legacyItem->getCompanyId() <= 0;
		}

		return true;
	}

	/**
	 * Required always or on the current stage, as configured by field attributes.
	 */
	private function isPairFieldRequired(PreparedItemOperation $preparedOperation, AbstractClientBindingCommand $command): bool
	{
		$ownerEntityTypeId = $command->getOwnerId()->getEntityType()->getId();
		if (!FieldAttributeManager::isEntitySupported($ownerEntityTypeId))
		{
			return false;
		}

		$factory = Container::getInstance()->getFactory($ownerEntityTypeId);
		if ($factory === null)
		{
			return false;
		}

		return in_array(
			$this->getPairFieldName($command),
			$preparedOperation->getOperation()->getStageDependantRequiredFields($factory),
			true,
		);
	}

	private function getPairFieldName(AbstractClientBindingCommand $command): string
	{
		$ownerEntityType = $command->getOwnerId()->getEntityType();
		if ($ownerEntityType->equals(EntityType::company()))
		{
			return 'CONTACT';
		}
		if ($ownerEntityType->equals(EntityType::contact()))
		{
			return 'COMPANY';
		}

		return EditorAdapter::FIELD_CLIENT;
	}

	/**
	 * @return array<int, array{sort: int, primary: bool}> bound id => binding, ordered by SORT
	 */
	private function readBindings(Item $item, bool $isContacts): array
	{
		$bindings = [];
		if ($isContacts)
		{
			foreach ($item->getContactBindings()?->getAll() ?? [] as $binding)
			{
				$bindings[$binding->getContactId()] = ['sort' => $binding->getSort(), 'primary' => $binding->isPrimary()];
			}
		}
		else
		{
			foreach ($item->getCompanyBindings()?->getAll() ?? [] as $binding)
			{
				$bindings[$binding->getCompanyId()] = ['sort' => $binding->getSort(), 'primary' => $binding->isPrimary()];
			}
		}

		uksort(
			$bindings,
			static fn(int $left, int $right): int => [$bindings[$left]['sort'], $left] <=> [$bindings[$right]['sort'], $right],
		);

		return $bindings;
	}

	/**
	 * Current SORT values are kept, so the binding rows that are not added or removed are not rewritten.
	 *
	 * @param array<int, array{sort: int, primary: bool}> $bindings
	 */
	private function writeBindings(Item $item, bool $isContacts, array $bindings): void
	{
		if ($isContacts)
		{
			$collection = new ContactBindingCollection();
			foreach ($bindings as $id => $binding)
			{
				$collection->add(new ContactBinding($id, $binding['sort'], $binding['primary']));
			}
			$item->setContactBindings($collection);

			return;
		}

		$collection = new CompanyBindingCollection();
		foreach ($bindings as $id => $binding)
		{
			$collection->add(new CompanyBinding($id, $binding['sort'], $binding['primary']));
		}
		$item->setCompanyBindings($collection);
	}

	/**
	 * @param array<int, array{sort: int, primary: bool}> $bindings
	 */
	private function findPrimaryId(array $bindings): ?int
	{
		foreach ($bindings as $id => $binding)
		{
			if ($binding['primary'])
			{
				return $id;
			}
		}

		return null;
	}

	/**
	 * @param int[] $processedIds
	 * @param int[] $skippedIds
	 * @param Error[] $errors
	 */
	private function createResult(array $processedIds, array $skippedIds, array $errors): Result
	{
		$result = new Result();
		$result->setData([
			'processedIds' => array_values($processedIds),
			'skippedIds' => array_values($skippedIds),
		]);
		if (!empty($errors))
		{
			$result->addErrors($errors);
		}

		return $result;
	}

	private function createError(string $code, array $replace = [], ?int $id = null): Error
	{
		$message = (string)(Loc::getMessage($code, $replace) ?? $code);

		return new Error($message, $code, $id === null ? null : ['id' => $id]);
	}
}
