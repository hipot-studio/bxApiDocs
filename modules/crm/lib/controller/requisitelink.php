<?php


namespace Bitrix\Crm\Controller;


use Bitrix\Crm\Requisite\EntityLink;
use Bitrix\Crm\Security\OwnerEntityListRestriction;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\UI\Filter\EntityHandler;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\UI\PageNavigation;

class RequisiteLink extends \Bitrix\Sale\Controller\Controller
{
	/** Matches no row: record identifiers are positive. */
	private const DENY_OWNER_FILTER = ['@ENTITY_ID' => [0]];

	public function getFieldsAction()
	{
		$entity = new \Bitrix\Crm\Order\Rest\Entity\RequisiteLink();
		return ['REQUISITE_LINK'=>$entity->prepareFieldInfos(
			$entity->getFields()
		)];
	}

	public function listAction(PageNavigation $pageNavigation, array $select = [], array $filter = [], array $order = []): Page
	{
		$select = empty($select)? ['*']:$select;
		$order = empty($order)? ['ENTITY_TYPE_ID'=>'ASC']:$order;

		$filter = $this->applyOwnerPermissionsFilter($filter);

		$links = EntityLink::getList(
			[
				'select'=>$select,
				'filter'=>$filter,
				'order'=>$order,
				'offset' => $pageNavigation->getOffset(),
				'limit' => $pageNavigation->getLimit()
			]
		)->fetchAll();

		return new Page('REQUISITE_LINKS', $links, function() use ($filter)
		{
			return count(
				EntityLink::getList(['filter'=>$filter])->fetchAll()
			);
		});
	}

	private function applyOwnerPermissionsFilter(array $filter): array
	{
		$restrictionFilter = $this->resolveOwnerPermissionsRestriction($filter);

		if ($restrictionFilter === null)
		{
			return $filter;
		}

		// combine via AND as a nested subfilter to avoid key conflicts with the existing filter
		return empty($filter) ? $restrictionFilter : [$filter, $restrictionFilter];
	}

	/**
	 * A filter fragment restricting rows to owners the current user may read, or null when no
	 * restriction is needed.
	 *
	 * When the request is pinned to a single parent entity (exact ENTITY_TYPE_ID and ENTITY_ID),
	 * the right is checked point-wise on that entity instead of building the restriction across
	 * every possible owner type - the latter walks all dynamic types and, per type, resolves the
	 * per-category permission set. The point-wise path is limited to the owner types the general
	 * path covers and that carry a modern item-permission model (a factory); anything else falls
	 * through to the general restriction.
	 */
	private function resolveOwnerPermissionsRestriction(array $filter): ?array
	{
		$availableTypeIds = EntityLink::getAvailableEntityTypeIds();

		$ownerTypeId = $this->exactOwnerBinding($filter, 'ENTITY_TYPE_ID');
		$ownerId = $this->exactOwnerBinding($filter, 'ENTITY_ID');
		if (
			$ownerTypeId !== null
			&& $ownerId !== null
			&& isset($availableTypeIds[$ownerTypeId])
			&& Container::getInstance()->getFactory($ownerTypeId) !== null
		)
		{
			$canRead = Container::getInstance()->getUserPermissions()->item()->canRead($ownerTypeId, $ownerId);

			return $canRead ? null : self::DENY_OWNER_FILTER;
		}

		return (new OwnerEntityListRestriction(
			Container::getInstance()->getUserPermissions()
		))->buildFilter(
			array_values($availableTypeIds),
			'ENTITY_TYPE_ID',
			'ENTITY_ID'
		);
	}

	/** The value of an exact, non-negated equality on $field in $filter, or null. */
	private function exactOwnerBinding(array $filter, string $field): ?int
	{
		$operation = EntityHandler::findFieldOperation($field, $filter);
		if (
			is_array($operation)
			&& ($operation['OPERATION'] ?? null) === '='
			&& ($operation['NEGATIVE'] ?? null) === 'N'
			&& isset($operation['CONDITION'])
			&& !is_array($operation['CONDITION'])
		)
		{
			return (int)$operation['CONDITION'];
		}

		return null;
	}

	static public function prepareFields($fields)
	{
		return isset($fields['REQUISITE_LINK'])?['REQUISITE_LINK'=>$fields['REQUISITE_LINK']]:[];
	}
}