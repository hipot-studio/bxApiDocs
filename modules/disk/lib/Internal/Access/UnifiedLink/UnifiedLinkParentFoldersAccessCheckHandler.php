<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Model\UnifiedLinkAccessTable;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class UnifiedLinkParentFoldersAccessCheckHandler extends ChainableAccessCheckHandler
{
	protected UnifiedLinkUserAccessResolver $userAccessResolver;

	/**
	 * @param int $userId
	 */
	public function __construct(
		protected readonly int $userId,
	)
	{
		$this->userAccessResolver = new UnifiedLinkUserAccessResolver($userId);
	}

	protected function doCheck(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		if (!$this->userAccessResolver->canUseUnifiedLink($baseObject))
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		$fetchResult = ObjectPathTable::query()
			->registerRuntimeField($this->getParentUnifiedLinkAccessReference())
			->setSelect([
				new ExpressionField(
					name: 'ACCESS_LEVEL',
					expression: 'MAX(%s)',
					buildFrom: 'PARENT_UNIFIED_LINK_ACCESS.ACCESS_LEVEL',
				),
			])
			->where('OBJECT_ID', (int)$baseObject->getId())
			->whereColumn('OBJECT_ID', '!=', 'PARENT_ID')
			->fetch()
		;

		$accessLevel = $fetchResult['ACCESS_LEVEL'] ?? null;

		if (is_string($accessLevel))
		{
			$accessLevel = UnifiedLinkAccessLevel::tryFrom($accessLevel);
		}

		return $accessLevel ?? UnifiedLinkAccessLevel::Denied;
	}

	protected function doCheckCollection(array $baseObjects): array
	{
		$objectIds = array_keys($baseObjects);
		$result = [];

		if (empty($objectIds))
		{
			return $result;
		}

		$queryResult = ObjectPathTable::query()
			->registerRuntimeField($this->getParentUnifiedLinkAccessReference())
			->setSelect([
				'OBJECT_ID',
				new ExpressionField(
					name: 'ACCESS_LEVEL',
					expression: 'MAX(%s)',
					buildFrom: 'PARENT_UNIFIED_LINK_ACCESS.ACCESS_LEVEL',
				),
			])
			->whereIn('OBJECT_ID', $objectIds)
			->whereColumn('OBJECT_ID', '!=', 'PARENT_ID')
			->setGroup(['OBJECT_ID'])
			->exec()
		;

		while ($row = $queryResult->fetch())
		{
			$objectId = (int)$row['OBJECT_ID'];
			$baseObject = $baseObjects[$objectId] ?? null;
			$accessLevel = UnifiedLinkAccessLevel::tryFrom((string)$row['ACCESS_LEVEL']);

			if (
				$baseObject instanceof BaseObject
				&& $accessLevel instanceof UnifiedLinkAccessLevel
				&& $accessLevel !== UnifiedLinkAccessLevel::Denied
				&& $this->userAccessResolver->canUseUnifiedLink($baseObject)
			)
			{
				$result[$objectId] = $accessLevel;
			}
		}

		return $result;
	}

	protected function getParentUnifiedLinkAccessReference(): Reference
	{
		return (new Reference(
			name: 'PARENT_UNIFIED_LINK_ACCESS',
			referenceEntity: UnifiedLinkAccessTable::class,
			referenceFilter: Join::on('this.PARENT_ID', 'ref.OBJECT_ID'),
		))->configureJoinType(Join::TYPE_INNER);
	}

	protected function getAccessSource(): UnifiedLinkAccessSource
	{
		return UnifiedLinkAccessSource::ParentUnifiedLink;
	}
}
