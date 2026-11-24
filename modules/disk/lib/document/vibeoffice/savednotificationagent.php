<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Vibeoffice\Logging\DatabaseFailureResult;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Disk\Document\Vibeoffice\Webhook\DeliveryTable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\WebhookProcessor;
use Bitrix\Main\Application;
use Bitrix\Main\Type\Callback;
use Throwable;

final class SavedNotificationAgent
{
	private const RETRY_INTERVAL = 60;

	public static function schedule(int $ledgerId, string $editorKey, int $rev): bool
	{
		return (bool)\CAgent::AddAgent(
			self::callback($ledgerId, $editorKey, $rev),
			'disk',
			'N',
			self::RETRY_INTERVAL,
			existError: false,
		);
	}

	public static function run(int $ledgerId, string $editorKey, int $rev): ?Callback
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$delivery = null;
		try
		{
			$delivery = DeliveryTable::getById($ledgerId)->fetch();
			if (!$delivery || $delivery['EVENT'] !== WebhookProcessor::EVENT_SAVED
				|| $delivery['STATUS'] !== DeliveryTable::STATUS_DONE
				|| $delivery['OUTCOME'] !== DeliveryTable::OUTCOME_APPLIED
				|| (int)$delivery['NOTIFIED'] === 1
				|| DeliveryTable::isSavedNotificationSkipped($delivery))
			{
				return null;
			}

			$result = (new SavedDocumentWriter())->complete($ledgerId, [
				'hostDocKey' => $delivery['HOST_DOC_KEY'],
				'editorKey' => $editorKey,
				'rev' => $rev,
			]);

			if ($result instanceof DatabaseFailureResult)
			{
				LoggerProvider::get('disk.vibeoffice.webhook')->error('vibeoffice webhook diagnostic', [
					'operation' => 'saved',
					'code' => 'delivery_exception',
					'event' => WebhookProcessor::EVENT_SAVED,
					'delivery_id' => $delivery['DELIVERY_ID'],
					'stage' => $result->getStage() ?? 'notify',
					'reason_code' => 'db_error',
				] + $result->getDiagnosticFields());
			}

			return $result->isSuccess() ? null : self::callback($ledgerId, $editorKey, $rev);
		}
		catch (Throwable $exception)
		{
			LoggerProvider::get('disk.vibeoffice.webhook')->error('vibeoffice webhook diagnostic', [
				'operation' => 'saved',
				'code' => 'delivery_exception',
				'exception_class' => $exception::class,
				'event' => WebhookProcessor::EVENT_SAVED,
				'delivery_id' => $delivery['DELIVERY_ID'] ?? null,
				'stage' => 'notify',
				'reason_code' => 'notification_failed',
			]);

			return self::callback($ledgerId, $editorKey, $rev);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function callback(int $ledgerId, string $editorKey, int $rev): Callback
	{
		return new Callback([self::class, 'run'], [$ledgerId, $editorKey, $rev]);
	}
}
