<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;

final class EditorFailureAdmission
{
	private const CONTEXT_KEY = 'editorFailureReports';
	private const LIMIT = 10;
	private const DIAGNOSTIC_CONTEXT_KEY = 'editorLoadingDiagnosticReportsV1';

	public function reserve(DocumentSession $session, EditorFailureReport $report): bool
	{
		$sessionId = (int)$session->getId();
		if (
			$sessionId <= 0
			|| $sessionId !== $report->get('document_session_id')
			|| $session->getServiceRaw() !== DocumentService::Vibeoffice->value
			|| $session->getUserId() <= 0
		)
		{
			return false;
		}

		$connection = Application::getConnection();
		if (DocumentStateRepository::hasOpenTransaction($connection))
		{
			return false;
		}

		$lockName = DocumentSessionManager::getSessionContextLockName($sessionId);
		if (!$connection->lock($lockName, 0))
		{
			return false;
		}

		$ownsTransaction = false;
		try
		{
			$connection->startTransaction();
			$ownsTransaction = true;
			$query = DocumentSessionTable::query()
				->setSelect(['ID', 'SERVICE', 'USER_ID', 'CONTEXT'])
				->where('ID', $sessionId)
			;
			$row = DocumentStateRepository::fetchForUpdate($query);
			if (
				$row === null
				|| (int)$row['ID'] !== $sessionId
				|| $row['SERVICE'] !== DocumentService::Vibeoffice->value
				|| (int)$row['USER_ID'] !== $session->getUserId()
			)
			{
				return false;
			}

			$context = self::decodeContext($row['CONTEXT']);
			if ($context === null)
			{
				return false;
			}
			$vo = array_key_exists(DocumentSessionManager::CONTEXT_VO_KEY, $context)
				? $context[DocumentSessionManager::CONTEXT_VO_KEY]
				: [];
			if (!is_array($vo))
			{
				return false;
			}
			$observed = $report->get('outcome') === 'observed';
			$contextKey = $observed ? self::DIAGNOSTIC_CONTEXT_KEY : self::CONTEXT_KEY;
			$history = ($observed ? self::readDiagnosticHistory(...) : self::readHistory(...))(
				array_key_exists($contextKey, $vo) ? $vo[$contextKey] : null,
				$sessionId,
				array_key_exists($contextKey, $vo),
			);
			if ($history === null)
			{
				return false;
			}

			$group = $observed ? 'observed' : ($report->get('outcome') === 'failed' ? 'errors' : 'recovered');
			$hash = $observed
				? hash('sha256', $sessionId . ':' . $report->get('report_attempt_id'))
				: $report->getDeduplicationHash();
			if (in_array($hash, $history[$group], true) || count($history[$group]) >= self::LIMIT)
			{
				return false;
			}

			$history[$group][] = $hash;
			$vo[$contextKey] = $history;
			$context[DocumentSessionManager::CONTEXT_VO_KEY] = $vo;
			$result = DocumentSessionTable::update($sessionId, [
				'CONTEXT' => Json::encode($context),
			]);
			if (!$result->isSuccess())
			{
				return false;
			}

			$connection->commitTransaction();
			$ownsTransaction = false;

			return true;
		}
		catch (\Throwable)
		{
			return false;
		}
		finally
		{
			try
			{
				if ($ownsTransaction)
				{
					$connection->rollbackTransaction();
				}
			}
			finally
			{
				$connection->unlock($lockName);
			}
		}
	}

	private static function decodeContext(mixed $raw): ?array
	{
		if (!is_string($raw))
		{
			return null;
		}
		try
		{
			$decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
			if (!is_object($decoded))
			{
				return null;
			}

			return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			return null;
		}
	}

	private static function readHistory(mixed $marker, int $sessionId, bool $exists): ?array
	{
		if (!$exists)
		{
			return [
				'version' => 2,
				'documentSessionId' => $sessionId,
				'errors' => [],
				'recovered' => [],
			];
		}
		if (
			!is_array($marker)
			|| count($marker) !== 4
			|| ($marker['version'] ?? null) !== 2
			|| !is_int($marker['documentSessionId'] ?? null)
			|| $marker['documentSessionId'] <= 0
			|| !self::validHashes($marker['errors'] ?? null)
			|| !self::validHashes($marker['recovered'] ?? null)
		)
		{
			return null;
		}
		if ($marker['documentSessionId'] !== $sessionId)
		{
			$marker['documentSessionId'] = $sessionId;
			$marker['errors'] = [];
			$marker['recovered'] = [];
		}

		return $marker;
	}

	private static function readDiagnosticHistory(mixed $marker, int $sessionId, bool $exists): ?array
	{
		if (!$exists)
		{
			return ['version' => 1, 'documentSessionId' => $sessionId, 'observed' => []];
		}
		if (
			!is_array($marker) || count($marker) !== 3 || ($marker['version'] ?? null) !== 1
			|| !is_int($marker['documentSessionId'] ?? null) || $marker['documentSessionId'] <= 0
			|| !self::validHashes($marker['observed'] ?? null) || count($marker['observed']) > self::LIMIT
		)
		{
			return null;
		}
		if ($marker['documentSessionId'] !== $sessionId)
		{
			$marker['documentSessionId'] = $sessionId;
			$marker['observed'] = [];
		}

		return $marker;
	}

	private static function validHashes(mixed $hashes): bool
	{
		if (!is_array($hashes) || !array_is_list($hashes))
		{
			return false;
		}
		$unique = [];
		foreach ($hashes as $hash)
		{
			if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 || isset($unique[$hash]))
			{
				return false;
			}
			$unique[$hash] = true;
		}

		return true;
	}
}
