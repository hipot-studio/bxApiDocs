<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\SmartProcessStartForms;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\SystemException;
use CCrmOwnerType;
use Throwable;

/**
 * Applies the entry context to an item created by a form opened from the start catalog.
 * A submission is never broken by the listener: the form is already filled.
 */
class SubmissionListener
{
	public static function onFormFilled(int $formId, array $properties, array $resultEntities): void
	{
		if (!Feature::enabled(SmartProcessStartForms::class))
		{
			return;
		}

		$signed = $properties[TicketService::PROPERTY] ?? null;
		if (!is_string($signed) || $signed === '')
		{
			return;
		}

		try
		{
			(new self())->handle($formId, $resultEntities, $signed);
		}
		catch (Throwable $error)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($error);
		}
	}

	private function handle(int $formId, array $resultEntities, string $signed): void
	{
		$ticket = (new TicketService())->parse($signed);
		if ($ticket === null || $ticket->userId <= 0)
		{
			return;
		}

		if ($formId !== $ticket->formId)
		{
			return;
		}

		$form = (new FormResolver())->getBoundForm($ticket->entityTypeId);
		if (
			$form === null
			|| $form['id'] !== $ticket->formId
			|| !CreateByForm::isFormPermitted(
				$ticket->entityTypeId,
				$form,
				Container::getInstance()->getUserPermissions($ticket->userId),
			)
		)
		{
			return;
		}

		$itemId = $this->findCreatedItemId($resultEntities, $ticket->entityTypeId);
		if ($itemId === null)
		{
			return;
		}

		$item = Container::getInstance()->getFactory($ticket->entityTypeId)?->getItem($itemId);
		if ($item === null)
		{
			return;
		}

		$result = (new SubmittedItem())->applyContext($item, $ticket->context, $ticket->userId);
		if (!$result->isSuccess())
		{
			throw new SystemException('Could not apply the start catalog context to the submitted item');
		}
	}

	private function findCreatedItemId(array $resultEntities, int $entityTypeId): ?int
	{
		$typeName = CCrmOwnerType::ResolveName($entityTypeId);
		if ($typeName === '')
		{
			return null;
		}

		foreach ($resultEntities as $entity)
		{
			if (
				is_array($entity)
				&& ($entity['ENTITY_TYPE'] ?? null) === $typeName
				&& ($entity['IS_DUPLICATE'] ?? true) === false
				&& (int)($entity['ENTITY_ID'] ?? 0) > 0
			)
			{
				return (int)$entity['ENTITY_ID'];
			}
		}

		return null;
	}
}
