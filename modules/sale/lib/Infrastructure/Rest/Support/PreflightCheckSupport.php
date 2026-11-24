<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;

/**
 * What the pre-flight check of a change refuses to assemble: the input the domain carries out
 * beyond the order while the order is being assembled, long before the save the check never makes.
 *
 * A return through the pay system is made the moment the mark is set (`Payment::onFieldModify()`
 * asks the handler for the refund, an HTTP request to the pay system for most of them), so the
 * check gave the money back and left the payment paid in the database, ready to be refunded again.
 * A payment or a shipment the input leaves out of its collection is deleted while the collection is
 * built, and the deletion does not wait for a save either: `crm` drops the timeline, the activities
 * and the document bindings of a payment and the realization, the timeline and the delivery
 * activities of a shipment; a shipment also loses the values of its properties and is taken out of
 * the request of its delivery service. The order stayed as it was, and what stood around it was gone.
 *
 * The check cannot tell how either would end without doing it, so it refuses such an input by the
 * path that asks for it, together with every other error of the input, and the change carries it
 * out as before. A return to the inner account of the buyer is not refused: the domain puts it in a
 * pool of the process that only a save empties, and no request leaves the portal.
 *
 * The system shipment is no element of the collection ({@see ElementLocator::find()}): the input
 * cannot name it, and the domain does not delete it.
 */
final class PreflightCheckSupport
{
	/**
	 * Collections whose existing elements the domain deletes when the input does not name them, with
	 * the name of one element.
	 */
	private const DELETED_WHEN_LEFT_OUT = [
		'payments' => 'payment',
		'shipments' => 'shipment',
	];

	/**
	 * @param Order $order the order the check is asked to change
	 * @return list<Error> one per return through the pay system the input starts, under the path of
	 *         its mark, and one per collection the input leaves an existing element out of, under the
	 *         path of the collection
	 */
	public static function validate(OrderDto $dto, Order $order): array
	{
		$values = DtoFieldsConverter::getInitializedValues($dto);

		return array_merge(
			self::validateReturnsThroughThePaySystem($values, $order),
			self::validateLeftOutElements($values, $order),
		);
	}

	/**
	 * A payment that is a return through the pay system already is not asked for the refund again:
	 * the domain sets a mark only when its value changes.
	 *
	 * @param array<string, mixed> $values
	 * @return list<Error>
	 */
	private static function validateReturnsThroughThePaySystem(array $values, Order $order): array
	{
		$errors = [];
		$index = 0;
		foreach ($values['payments'] ?? [] as $element)
		{
			$path = 'payments.' . $index++ . '.isReturn';
			if (!$element instanceof Dto)
			{
				continue;
			}

			if ((DtoFieldsConverter::getInitializedValues($element)['isReturn'] ?? null) !== Payment::RETURN_PS)
			{
				continue;
			}

			$id = ElementIdentity::existingEntityId($element);
			// An element naming an entity the order does not have is refused by its identifier elsewhere.
			$payment = $id === null ? null : ElementLocator::find($order, 'payments', $id);
			if ($payment === null || $payment->getField('IS_RETURN') === Payment::RETURN_PS)
			{
				continue;
			}

			$errors[] = new Error(
				'Field "' . $path . '" returns payment ' . $id . ' through its pay system, which a check cannot do:'
				. ' the pay system is asked for the money at once, so a return is made by a change',
				$path,
			);
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return list<Error>
	 */
	private static function validateLeftOutElements(array $values, Order $order): array
	{
		$errors = [];
		foreach (self::DELETED_WHEN_LEFT_OUT as $property => $entityName)
		{
			if (!array_key_exists($property, $values))
			{
				continue;
			}

			$named = [];
			foreach ($values[$property] ?? [] as $element)
			{
				$id = $element instanceof Dto ? ElementIdentity::existingEntityId($element) : null;
				if ($id !== null)
				{
					$named[$id] = true;
				}
			}

			$leftOut = [];
			foreach (self::existingElements($order, $property) as $entity)
			{
				$id = (int)$entity->getId();
				if (!isset($named[$id]))
				{
					$leftOut[] = $id;
				}
			}

			if ($leftOut !== [])
			{
				$noun = count($leftOut) === 1 ? $entityName : $property;
				$errors[] = new Error(
					'Field "' . $property . '" leaves out ' . $noun . ' ' . implode(', ', $leftOut)
					. ' of the order, which a check cannot delete: the deletion is written at once',
					$property,
				);
			}
		}

		return $errors;
	}

	/**
	 * @return iterable<\Bitrix\Sale\Internals\CollectableEntity>
	 */
	private static function existingElements(Order $order, string $property): iterable
	{
		return $property === 'payments'
			? $order->getPaymentCollection()
			: $order->getShipmentCollection()->getNotSystemItems()
		;
	}
}
