<?php

namespace Bitrix\Crm\Controller;

use Bitrix\Crm\Order\ContactCompanyCollection;
use Bitrix\Crm\Service\Container;

use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\Error;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Sale\Registry;

class OrderContactCompany extends \Bitrix\Sale\Controller\Controller
{
	private const DEFAULT_ORDER = ['ID' => 'ASC'];

	public function getFieldsAction(): array
	{
		$entity = new \Bitrix\Crm\Order\Rest\Entity\OrderContactCompany();

		return [
			'CLIENT' => $entity->prepareFieldInfos($entity->getFields()),
		];
	}

	public function listAction(
		PageNavigation $pageNavigation,
		array $select = [],
		array $filter = [],
		array $order = []
	): ?Page
	{
		// every check runs, so that a request breaking more than one of them is answered with every error at once
		$safeFilter = $this->buildSafeFilter($filter);
		$isSelectAllowed = $this->isSelectAllowed($select);
		$isOrderAllowed = $this->isOrderAllowed($order);

		if ($safeFilter === null || !$isSelectAllowed || !$isOrderAllowed)
		{
			return null;
		}

		$select = empty($select) ? self::getAllowedFields() : $select;
		$order = empty($order) ? self::DEFAULT_ORDER : $order;

		$canReadOrder = Container::getInstance()
			->getUserPermissions()
			->item()
			->canRead(\CCrmOwnerType::Order, $safeFilter['=ORDER_ID'])
		;
		if (!$canReadOrder)
		{
			// an unreadable order is answered with an empty list instead of a refusal, so that the
			// answer does not tell whether such an order exists
			return new Page('CLIENTS', [], 0);
		}

		$registry = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER);

		/** @var ContactCompanyCollection $contactCompanyCollection */
		$contactCompanyCollection = $registry->get(ENTITY_CRM_CONTACT_COMPANY_COLLECTION);

		$tradeBindings = $contactCompanyCollection::getList([
			'select' => $select,
			'filter' => $safeFilter,
			'order' => $order,
			'offset' => $pageNavigation->getOffset(),
			'limit' => $pageNavigation->getLimit(),
		])->fetchAll();

		// the counter runs the very conditions of the selection above, so it counts the bindings of
		// the order the permission has been checked for and not of whatever the client asked
		return new Page('CLIENTS', $tradeBindings, static function() use ($safeFilter)
		{
			$registry = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER);

			/** @var ContactCompanyCollection $contactCompanyCollection */
			$contactCompanyCollection = $registry->get(ENTITY_CRM_CONTACT_COMPANY_COLLECTION);

			return count(
				$contactCompanyCollection::getList(['filter'=>$safeFilter])->fetchAll()
			);
		});
	}

	/**
	 * The conditions of the selection built from the filter of the client: an exact equality per
	 * whitelisted field, bound to the single order the whole request addresses. Null is answered for
	 * a filter this action refuses, the errors being added by then.
	 */
	private function buildSafeFilter(array $filter): ?array
	{
		$safeFilter = [];

		foreach ($filter as $key => $value)
		{
			$fieldName = is_string($key) && str_starts_with($key, '=') ? mb_substr($key, 1) : $key;

			if (!in_array($fieldName, self::getAllowedFields(), true))
			{
				$this->addError($this->getFieldNotAllowedError('filter', $key));

				return null;
			}

			if (!is_scalar($value))
			{
				$this->addError(
					new Error("Field '{$fieldName}' of 'filter' accepts an exact value only", ErrorCode::INVALID_ARG_VALUE)
				);

				return null;
			}

			$safeFilter['=' . $fieldName] = $value;
		}

		$orderId = (int)($safeFilter['=ORDER_ID'] ?? 0);
		if ($orderId <= 0)
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('filter[ORDER_ID]'));

			return null;
		}

		$safeFilter['=ORDER_ID'] = $orderId;

		return $safeFilter;
	}

	private function isSelectAllowed(array $select): bool
	{
		foreach ($select as $fieldName)
		{
			if (!in_array($fieldName, self::getAllowedFields(), true))
			{
				$this->addError($this->getFieldNotAllowedError('select', $fieldName));

				return false;
			}
		}

		return true;
	}

	private function isOrderAllowed(array $order): bool
	{
		foreach (array_keys($order) as $fieldName)
		{
			if (!in_array($fieldName, self::getAllowedFields(), true))
			{
				$this->addError($this->getFieldNotAllowedError('order', $fieldName));

				return false;
			}
		}

		return true;
	}

	/** The fields the entity of this action declares, the very set answered by getFieldsAction. */
	private static function getAllowedFields(): array
	{
		static $fields = null;

		if ($fields === null)
		{
			$fields = array_keys((new \Bitrix\Crm\Order\Rest\Entity\OrderContactCompany())->getFields());
		}

		return $fields;
	}

	private function getFieldNotAllowedError(string $argumentName, mixed $fieldName): Error
	{
		$name = is_scalar($fieldName) ? (string)$fieldName : '';

		return new Error("Field '{$name}' is not allowed in '{$argumentName}'", ErrorCode::INVALID_ARG_VALUE);
	}

	public static function prepareFields($fields): array
	{
		$data = [
			\CCrmOwnerType::Company=>[],
			\CCrmOwnerType::Contact=>[],
		];

		$contactIsPrimary = false;

		if (isset($fields['CLIENTS']))
		{
			foreach ($fields['CLIENTS'] as $client)
			{
				//TODO: must be included in the field check. Fields in rest are described as required
				if (
					(int)$client['ENTITY_TYPE_ID'] <= 0
					|| (int)$client['ENTITY_ID'] <= 0
				)
				{
					continue;
				}

				$client['IS_PRIMARY'] = isset($client['IS_PRIMARY']) && $client['IS_PRIMARY'] == 'Y' ? 'Y' : 'N';

				// there can only be one company
				if (empty($data[\CCrmOwnerType::Company]))
				{
					if ((int)$client['ENTITY_TYPE_ID'] === \CCrmOwnerType::Company)
					{
						// the company must be isPrimary because the matcher requires this
						$client['IS_PRIMARY'] = 'Y';
						$data[\CCrmOwnerType::Company][] = $client;
					}
				}

				if ((int)$client['ENTITY_TYPE_ID'] === \CCrmOwnerType::Contact)
				{
					if (!$contactIsPrimary)
					{
						$contactIsPrimary = $client['IS_PRIMARY'] === 'Y';
					}

					$data[\CCrmOwnerType::Contact][] = $client;
				}
			}

			if (
				!empty($data[\CCrmOwnerType::Contact])
				&& !$contactIsPrimary
			)
			{
				// if none of the transferred contacts is isPrimary, set the flag for the first
				$data[\CCrmOwnerType::Contact][0]['IS_PRIMARY'] = 'Y';
			}

			return [
				'CLIENTS' => array_merge(
					$data[\CCrmOwnerType::Company],
					$data[\CCrmOwnerType::Contact]
				),
			];
		}

		return [];
	}
}
