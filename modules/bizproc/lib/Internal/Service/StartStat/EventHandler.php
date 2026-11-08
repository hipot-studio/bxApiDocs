<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StartStat;

use Bitrix\Bizproc\Internal\Model\StartStat\WorkflowStartStatTable;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Keeps the daily buckets of a template from outliving it: every day it was started on goes in one
 * statement. Never throws - the statistics are derived data and their loss must not break the deletion
 * of the template, which the event is only a part of.
 *
 * Deleting the buckets and taking the logger are separate methods so that a test double can make each
 * of them fail; nothing else is meant to be overridden.
 */
class EventHandler
{
	private const LOGGER_ID = 'bizproc.start_list_order';

	public static function onAfterWorkflowTemplateDelete(Event $event): void
	{
		$templateId = (int)$event->getParameter('ID');
		if ($templateId <= 0)
		{
			return;
		}

		try
		{
			static::deleteBuckets($templateId);
		}
		catch (Throwable $error)
		{
			self::warn('Bizproc: the start statistics of a deleted template were not removed', [
				'templateId' => $templateId,
				'error' => $error->getMessage(),
				'errorClass' => get_class($error),
			]);
		}
	}

	protected static function deleteBuckets(int $templateId): void
	{
		WorkflowStartStatTable::deleteByFilter(['=TEMPLATE_ID' => $templateId]);
	}

	protected static function getLogger(): LoggerInterface
	{
		return (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	/**
	 * @param array<string, mixed> $context Safe identifiers of the template only - never its scheme, the
	 * documents it was started on or the values of its fields.
	 */
	private static function warn(string $message, array $context): void
	{
		try
		{
			static::getLogger()->warning($message, $context);
		}
		catch (Throwable)
		{
			// a broken log must not turn lost statistics into a template that cannot be deleted
		}
	}
}
