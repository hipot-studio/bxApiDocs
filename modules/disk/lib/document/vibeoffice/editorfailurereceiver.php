<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;

final class EditorFailureReceiver
{
	public function receive(EditorFailureReport $report, int $userId): ?bool
	{
		if ($userId <= 0)
		{
			return null;
		}

		try
		{
			$session = DocumentSession::load([
				'=ID' => $report->get('document_session_id'),
				'=SERVICE' => DocumentService::Vibeoffice->value,
				'=USER_ID' => $userId,
			]);
		}
		catch (\Throwable)
		{
			return false;
		}
		if (!$session instanceof DocumentSession)
		{
			return null;
		}

		try
		{
			if (!(new EditorFailureAdmission())->reserve($session, $report))
			{
				return false;
			}
		}
		catch (\Throwable)
		{
			return false;
		}

		$outcome = $report->get('outcome');
		$level = $outcome === 'recovered' ? 'warning' : 'error';
		$event = $outcome === 'recovered' ? 'vibeoffice editor recovered' : 'vibeoffice editor failure';
		try
		{
			LoggerProvider::get('disk.vibeoffice.open')->log(
				$level,
				$event,
				$report->toArray() + ['user_id' => $userId],
			);
		}
		catch (\Throwable)
		{
			// The reserved slot is intentionally not returned after a logging failure.
		}

		return true;
	}
}
