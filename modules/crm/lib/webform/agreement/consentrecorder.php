<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage crm
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Crm\WebForm\Agreement;

use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Integration\UserConsent as CrmIntegrationUserConsent;
use Bitrix\Crm\WebForm\Debug\ResultEntityDebugLogger;
use Bitrix\Main\UserConsent\Consent;
use Bitrix\Main\UserConsent\ConsentUrl;
use Bitrix\Main\UserConsent\Internals\ConsentTable;

/**
 * Writes the acceptance set of one submission to the consent journal of `main`.
 *
 * The journal row is the legally significant trace of the acceptance, so the answer of `main` is
 * never dropped. A document that could not be written is put into a background retry bound to the
 * CRM result and activity of the same submission; the visitor still gets a successful response, so
 * a failing journal delays the record instead of losing the lead. Every attempt that does not reach
 * the journal leaves a diagnostic trail behind.
 *
 * The retry carries identifiers only. The address and the page of the visitor are read back from
 * the activity of the submission, so neither the agent queue nor the diagnostic trail becomes a
 * second copy of the personal data of the visitor.
 *
 * A submission that produced no activity is recorded all the same, only with nothing to bind the
 * row to: the address of the visitor arrives with the submission itself and is nowhere to be found
 * afterwards, so an unbound record is better than the missing one a retry could never write.
 */
final class ConsentRecorder
{
	public const ERROR_CRM_CONTEXT_MISSING = 'CRM_WEBFORM_CONSENT_CRM_CONTEXT_MISSING';
	public const ERROR_SUBMISSION_CONTEXT_MISSING = 'CRM_WEBFORM_CONSENT_SUBMISSION_CONTEXT_MISSING';
	public const ERROR_NOT_WRITTEN = 'CRM_WEBFORM_CONSENT_NOT_WRITTEN';
	public const ERROR_WRITE_FAILED = 'CRM_WEBFORM_CONSENT_WRITE_FAILED';
	public const ERROR_RETRY_NOT_QUEUED = 'CRM_WEBFORM_CONSENT_RETRY_NOT_QUEUED';
	public const ERROR_RETRY_LIMIT_REACHED = 'CRM_WEBFORM_CONSENT_RETRY_LIMIT_REACHED';

	private const RETRY_LIMIT = 5;
	private const RETRY_INTERVAL_SEC = 600;
	private const AGENT_MODULE_ID = 'crm';
	private const AGENT_STOP = '';

	public function __construct(
		private readonly int $formId,
		private readonly int $resultId,
		private readonly int $activityId,
		private readonly ResultEntityDebugLogger $debugLogger,
	)
	{
	}

	/**
	 * Records the acceptance set of a submission that has already produced a CRM result.
	 *
	 * @param int[] $agreementIds Documents accepted according to the saved policy of the form.
	 * @param string|null $url Page the visitor accepted the documents on.
	 * @param string|null $ip Address the visitor accepted the documents from.
	 */
	public function record(array $agreementIds, ?string $url, ?string $ip): void
	{
		try
		{
			$this->recordAccepted(self::normalize($agreementIds), $url, $ip);
		}
		catch (\Throwable $exception)
		{
			// The submission has already succeeded and the journal never takes that back from the
			// visitor; what is missing from it is diagnosed by the trail of the failed attempt.
		}
	}

	/**
	 * @param int[] $pending
	 */
	private function recordAccepted(array $pending, ?string $url, ?string $ip): void
	{
		if (!$pending)
		{
			return;
		}

		$notRecorded = $this->writeEach($pending, ['URL' => (string)$url, 'IP' => (string)$ip]);
		if (!$notRecorded)
		{
			return;
		}

		if (!$this->hasCrmContext())
		{
			// A retry restores the address of the visitor from the activity of the submission, so
			// without one it would stop on the same check instead of ever reaching the journal.
			$this->logNotRecorded($notRecorded, self::ERROR_RETRY_NOT_QUEUED);

			return;
		}

		$this->enqueueRetry($notRecorded, self::currentUserId(), 1);
	}

	/**
	 * Agent entry point of the background retry.
	 *
	 * @param string $agreementIds Comma separated identifiers of the documents still to record.
	 * @return string Name of the next attempt, or an empty string to remove the agent.
	 */
	public static function retryAgent(
		int $formId,
		int $resultId,
		int $activityId,
		int $userId,
		int $attempt,
		string $agreementIds,
	): string
	{
		$debugLogger = (new ResultEntityDebugLogger())->setFormId($formId);
		$recorder = new self($formId, $resultId, $activityId, $debugLogger);

		return $recorder->retry(self::parseIds($agreementIds), $userId, $attempt);
	}

	/**
	 * Repeats the write of the documents that are still missing from the journal.
	 *
	 * @param int[] $agreementIds
	 * @return string Name of the next attempt, or an empty string when the retry is over.
	 */
	public function retry(array $agreementIds, int $userId, int $attempt): string
	{
		$pending = self::normalize($agreementIds);
		if (!$pending)
		{
			return self::AGENT_STOP;
		}

		if (!$this->hasCrmContext())
		{
			// The acceptance was never bound to CRM and no later attempt can bind it, so this one
			// leaves the trail behind and removes itself instead of repeating for nothing.
			$this->logNotRecorded($pending, self::ERROR_CRM_CONTEXT_MISSING);

			return self::AGENT_STOP;
		}

		try
		{
			$notRecorded = $this->writeRemaining($pending, $userId);
		}
		catch (\Throwable $exception)
		{
			$this->logNotRecorded($pending, self::ERROR_WRITE_FAILED);
			$notRecorded = $pending;
		}

		if (!$notRecorded)
		{
			return self::AGENT_STOP;
		}

		return $this->scheduleNextAttempt($notRecorded, $userId, $attempt);
	}

	/**
	 * Tells whether the acceptance can be bound to CRM: `main` keeps ORIGINATOR_ID/ORIGIN_ID of the
	 * row only when it is given an origin, and the background retry restores the submission through
	 * the same activity. Without one the row is still written, just with nothing to bind it to.
	 */
	private function hasCrmContext(): bool
	{
		return $this->activityId > 0;
	}

	/**
	 * @param int[] $agreementIds
	 * @return int[] Documents that are still missing from the journal.
	 */
	private function writeRemaining(array $agreementIds, int $userId): array
	{
		$agreementIds = $this->notRecordedOnly($agreementIds);
		if (!$agreementIds)
		{
			return [];
		}

		$context = $this->readSubmissionContext();
		if ($context === null)
		{
			$this->logNotRecorded($agreementIds, self::ERROR_SUBMISSION_CONTEXT_MISSING);

			return $agreementIds;
		}

		if ($userId > 0)
		{
			$context['USER_ID'] = $userId;
		}

		return $this->writeEach($agreementIds, $context);
	}

	/**
	 * @param int[] $agreementIds
	 * @param array $params Extra params of {@see Consent::addByContext()}.
	 * @return int[] Documents that are still missing from the journal.
	 */
	private function writeEach(array $agreementIds, array $params): array
	{
		$notRecorded = [];
		foreach ($agreementIds as $agreementId)
		{
			if (!$this->write($agreementId, $params))
			{
				$notRecorded[] = $agreementId;
			}
		}

		return $notRecorded;
	}

	private function write(int $agreementId, array $params): bool
	{
		try
		{
			$consentId = Consent::addByContext(
				$agreementId,
				CrmIntegrationUserConsent::PROVIDER_CODE,
				$this->activityId,
				$params,
			);
		}
		catch (\Throwable $exception)
		{
			$this->logNotRecorded([$agreementId], self::ERROR_WRITE_FAILED);

			return false;
		}

		if ($consentId === null)
		{
			$this->logNotRecorded([$agreementId], self::ERROR_NOT_WRITTEN);

			return false;
		}

		return true;
	}

	/**
	 * @param int[] $agreementIds
	 * @return int[] Documents of the set that the journal does not hold yet.
	 */
	private function notRecordedOnly(array $agreementIds): array
	{
		$recorded = $this->readRecorded($agreementIds);

		return array_values(array_filter(
			$agreementIds,
			static fn(int $agreementId) => !isset($recorded[$agreementId]),
		));
	}

	/**
	 * The whole set is looked up at once: the journal grows by a row per submission, and a query
	 * per document would repeat the same scan of it for each of them.
	 *
	 * @param int[] $agreementIds
	 * @return array<int, true> Documents of the set the journal already holds.
	 */
	private function readRecorded(array $agreementIds): array
	{
		if (!$agreementIds)
		{
			return [];
		}

		$rows = ConsentTable::query()
			->setSelect(['AGREEMENT_ID'])
			->whereIn('AGREEMENT_ID', $agreementIds)
			->where('ORIGINATOR_ID', CrmIntegrationUserConsent::PROVIDER_CODE)
			->where('ORIGIN_ID', (string)$this->activityId)
			->exec()
		;

		$recorded = [];
		while ($row = $rows->fetch())
		{
			$recorded[(int)$row['AGREEMENT_ID']] = true;
		}

		return $recorded;
	}

	/**
	 * Restores the submission context from the activity created by the same submission.
	 *
	 * The address of the visitor is mandatory for the journal and belongs to the moment of the
	 * submission: an agent runs inside a foreign request, so taking the address from the current
	 * one would write another visitor into a legally significant record. The activity is therefore
	 * matched by the whole submission tuple: a row belonging to another form or another result
	 * supplies no context at all instead of the context of a foreign visitor.
	 *
	 * @return array{IP: string, URL: string}|null
	 */
	private function readSubmissionContext(): ?array
	{
		$row = ActivityTable::getRow([
			'select' => ['PROVIDER_PARAMS'],
			'filter' => [
				'=ID' => $this->activityId,
				'=PROVIDER_ID' => Provider\WebForm::PROVIDER_ID,
				'=PROVIDER_TYPE_ID' => (string)$this->formId,
				'=ASSOCIATED_ENTITY_ID' => $this->resultId,
			],
		]);
		if (!$row)
		{
			return null;
		}

		$params = $row['PROVIDER_PARAMS'] ?? null;
		if (!is_array($params))
		{
			return null;
		}

		$ip = (string)($params['FORM']['IP'] ?? '');
		if (filter_var($ip, FILTER_VALIDATE_IP) === false)
		{
			return null;
		}

		$url = self::readSubmissionUrl($params);
		if ($url === null)
		{
			return null;
		}

		return [
			'IP' => $ip,
			'URL' => $url,
		];
	}

	private static function readSubmissionUrl(array $providerParams): ?string
	{
		$url = $providerParams['FORM']['SUBMISSION_URL'] ?? null;
		if (!is_string($url) || !method_exists(ConsentUrl::class, 'isAllowed'))
		{
			return null;
		}

		// Match the native writer's validation of the value it can store. An old activity has no
		// exact submission URL: visited pages and the public form link cannot safely reconstruct it.
		// Passing an empty or disallowed URL would make main substitute the agent's current request.
		return ConsentUrl::isAllowed(mb_substr($url, 0, 4000)) ? $url : null;
	}

	/**
	 * @param int[] $agreementIds
	 */
	private function scheduleNextAttempt(array $agreementIds, int $userId, int $attempt): string
	{
		if ($attempt >= self::RETRY_LIMIT)
		{
			$this->logNotRecorded($agreementIds, self::ERROR_RETRY_LIMIT_REACHED);

			return self::AGENT_STOP;
		}

		return $this->buildAgentName($agreementIds, $userId, $attempt + 1);
	}

	/**
	 * @param int[] $agreementIds
	 */
	private function enqueueRetry(array $agreementIds, int $userId, int $attempt): void
	{
		try
		{
			$agentId = \CAgent::AddAgent(
				$this->buildAgentName($agreementIds, $userId, $attempt),
				self::AGENT_MODULE_ID,
				'N',
				self::RETRY_INTERVAL_SEC,
				'',
				'Y',
				\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::RETRY_INTERVAL_SEC, 'FULL'),
				100,
				false,
				false,
			);
			if ((int)$agentId <= 0)
			{
				$this->logNotRecorded($agreementIds, self::ERROR_RETRY_NOT_QUEUED);
			}
		}
		catch (\Throwable $exception)
		{
			$this->logNotRecorded($agreementIds, self::ERROR_RETRY_NOT_QUEUED);
		}
	}

	/**
	 * The agent is stored as evaluated PHP, so every part of the name is built from integers.
	 *
	 * @param int[] $agreementIds
	 */
	private function buildAgentName(array $agreementIds, int $userId, int $attempt): string
	{
		return sprintf(
			"%s::retryAgent(%d,%d,%d,%d,%d,'%s');",
			self::class,
			$this->formId,
			$this->resultId,
			$this->activityId,
			$userId,
			$attempt,
			implode(',', $agreementIds),
		);
	}

	/**
	 * @param int[] $agreementIds
	 */
	private function logNotRecorded(array $agreementIds, string $errorCode): void
	{
		foreach ($agreementIds as $agreementId)
		{
			$this->debugLogger->logConsentNotRecorded(
				$this->resultId,
				$this->activityId,
				$agreementId,
				$errorCode,
			);
		}
	}

	/**
	 * @return int[]
	 */
	private static function parseIds(string $agreementIds): array
	{
		return self::normalize(explode(',', $agreementIds));
	}

	/**
	 * @param array $agreementIds
	 * @return int[] Positive identifiers without repetitions, in the order they were accepted.
	 */
	private static function normalize(array $agreementIds): array
	{
		$normalized = [];
		foreach ($agreementIds as $agreementId)
		{
			$agreementId = (int)$agreementId;
			if ($agreementId > 0)
			{
				$normalized[$agreementId] = $agreementId;
			}
		}

		return array_values($normalized);
	}

	/**
	 * The visitor of a public form is usually anonymous, but a submission made by an authorized
	 * user belongs to that user and the retry must not lose it: an agent runs with no user at all.
	 */
	private static function currentUserId(): int
	{
		$user = $GLOBALS['USER'] ?? null;

		return is_object($user) ? (int)$user->GetID() : 0;
	}
}
