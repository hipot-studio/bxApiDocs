<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectPathTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class ExternalLinkParentFoldersAccessCheckHandler extends ChainableAccessCheckHandler
{
	protected function doCheck(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		$fetchResult = ObjectPathTable::query()
			->registerRuntimeField($this->getParentExternalLinkReference())
			->setSelect([
				new ExpressionField(
					name: 'ACCESS_RIGHT',
					expression: 'MAX(%s)',
					buildFrom: 'PARENT_EXTERNAL_LINK.ACCESS_RIGHT',
				),
			])
			->where('OBJECT_ID', (int)$baseObject->getId())
			->whereColumn('OBJECT_ID', '!=', 'PARENT_ID')
			->where('PARENT_EXTERNAL_LINK.IS_EXPIRED', false)
			->where('PARENT_EXTERNAL_LINK.TYPE', ExternalLinkTable::TYPE_MANUAL)
			->fetch()
		;

		$accessRight = $fetchResult['ACCESS_RIGHT'] ?? null;

		if (is_string($accessRight))
		{
			$accessRight = (int)$accessRight;
		}

		if (!is_int($accessRight))
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		return $this->mapAccessRightToAccessLevel($accessRight);
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
			->registerRuntimeField($this->getParentExternalLinkReference())
			->setSelect([
				'OBJECT_ID',
				new ExpressionField(
					name: 'ACCESS_RIGHT',
					expression: 'MAX(%s)',
					buildFrom: 'PARENT_EXTERNAL_LINK.ACCESS_RIGHT',
				),
			])
			->whereIn('OBJECT_ID', $objectIds)
			->whereColumn('OBJECT_ID', '!=', 'PARENT_ID')
			->where('PARENT_EXTERNAL_LINK.IS_EXPIRED', false)
			->where('PARENT_EXTERNAL_LINK.TYPE', ExternalLinkTable::TYPE_MANUAL)
			->setGroup(['OBJECT_ID'])
			->exec()
		;

		while ($row = $queryResult->fetch())
		{
			$objectId = (int)$row['OBJECT_ID'];
			$accessLevel = $this->mapAccessRightToAccessLevel((int)$row['ACCESS_RIGHT']);

			if (isset($baseObjects[$objectId]))
			{
				$result[$objectId] = $accessLevel;
			}
		}

		return $result;
	}

	protected function getParentExternalLinkReference(): Reference
	{
		return (new Reference(
			name: 'PARENT_EXTERNAL_LINK',
			referenceEntity: ExternalLinkTable::class,
			referenceFilter: Join::on('this.PARENT_ID', 'ref.OBJECT_ID'),
		))->configureJoinType(Join::TYPE_INNER);
	}

	protected function getAccessSource(): UnifiedLinkAccessSource
	{
		return UnifiedLinkAccessSource::ParentExternalLink;
	}

	protected function mapAccessRightToAccessLevel(int $accessRight): UnifiedLinkAccessLevel
	{
		return match ($accessRight)
		{
			ExternalLinkTable::ACCESS_RIGHT_VIEW => UnifiedLinkAccessLevel::Read,
			ExternalLinkTable::ACCESS_RIGHT_EDIT => UnifiedLinkAccessLevel::Edit,
			default => UnifiedLinkAccessLevel::Denied,
		};
	}
}
