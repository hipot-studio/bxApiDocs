<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\ClientBinding;

use Bitrix\Crm\V2\Internal\Service\Item\Handler\ClientBindingCommandHandler;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Main\Result;

/**
 * Batch change of contact or company bindings of one owner item.
 * Required user fields of the owner are not checked by default, even after setScope().
 */
abstract class AbstractClientBindingCommand extends AbstractItemCommand
{
	public const MAX_IDS = 100;

	/** @var int[] */
	private readonly array $ids;

	/**
	 * @param int[] $ids
	 */
	public function __construct(
		private readonly ItemId $ownerId,
		array $ids,
		int $userId,
	)
	{
		parent::__construct($userId);

		$this->ids = self::normalizeIds($ids);
		$this->withoutRequiredUserFieldsCheck();
	}

	public function getOwnerId(): ItemId
	{
		return $this->ownerId;
	}

	/**
	 * @return int[] positive ids without duplicates, in input order
	 */
	public function getIds(): array
	{
		return $this->ids;
	}

	abstract public function getChildEntityType(): EntityType;

	public function setScope(Scope $scope): static
	{
		parent::setScope($scope);
		$this->withoutRequiredUserFieldsCheck();

		return $this;
	}

	protected function execute(): Result
	{
		return (new ClientBindingCommandHandler())->handle($this);
	}

	/**
	 * @param array<mixed> $ids
	 * @return int[]
	 */
	private static function normalizeIds(array $ids): array
	{
		$normalized = [];
		foreach ($ids as $id)
		{
			if (is_string($id) && is_numeric($id))
			{
				$id = (int)$id;
			}
			if (is_int($id) && $id > 0)
			{
				$normalized[$id] = $id;
			}
		}

		return array_values($normalized);
	}
}
