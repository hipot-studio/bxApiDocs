<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Vibeoffice\Logging\DiagnosticContext;
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

		$this->logAcceptedReport($session, $report, $userId);

		return true;
	}

	private function logAcceptedReport(DocumentSession $session, EditorFailureReport $report, int $userId): void
	{
		$documentCorrelationId = null;
		try
		{
			$documentCorrelationId = DiagnosticContext::documentCorrelationId(HostDocumentKey::resolve($session));
		}
		catch (\Throwable)
		{
			// The authorized report remains accepted when its diagnostic scope is unavailable.
		}
		$outcome = $report->get('outcome');
		$level = $outcome === 'observed' ? 'info' : ($outcome === 'failed' ? 'error' : 'warning');
		$event = match ($outcome)
		{
			'recovered' => 'vibeoffice editor recovered',
			'observed' => 'vibeoffice editor loading diagnostic',
			'data-loss-risk' => 'vibeoffice editor recovery risk',
			default => 'vibeoffice editor failure',
		};
		try
		{
			LoggerProvider::get('disk.vibeoffice.open')->log(
				$level,
				$event,
				$report->toArray() + [
					'user_id' => $userId,
					'document_correlation_id' => $documentCorrelationId,
				],
			);
		}
		catch (\Throwable)
		{
			// The reserved slot is intentionally not returned after a logging failure.
		}
	}
}
