<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Services\Share;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * Embedded credentials for a guest who opened a public link.
 *
 * The instance is asked directly instead of through Dashboard::loadCredentials(): that method keeps
 * the answer inside the model and reports the refusal as an unchanged credentials field, so the reason
 * is lost. A guest has to be told which of the two external states they are in, and support has to be
 * able to tell a failing instance from a dead link.
 */
final class GuestCredentialsService
{
	public const ERROR_CODE = 'DASHBOARD_UNAVAILABLE';

	public const AUDIT_TYPE_ID = 'BICONNECTOR_SHARE_ACCESS_ERROR';

	/**
	 * What the entry of the journal stands for: the one link it names, or the group of links the window
	 * had no entries left for.
	 */
	public const SCOPE_LINK = 'link';

	public const SCOPE_UNNAMED_LINKS = 'unnamedLinks';

	private const MODULE_ID = 'biconnector';

	private const ERROR_MESSAGE = 'Dashboard credentials unavailable.';

	private readonly GuestRefusalJournalThrottle $journalThrottle;

	public function __construct(?GuestRefusalJournalThrottle $journalThrottle = null)
	{
		$this->journalThrottle = $journalThrottle ?? new GuestRefusalJournalThrottle();
	}

	/**
	 * @param int $shareId Identifier of the public link the guest came by; the journal entry names it.
	 * @param array $rlsRules Row level security rules for the guest token.
	 * @param int $expSeconds Guest token lifetime.
	 * @param Dto\User|null $author Whose identity the guest token carries: the author of the link.
	 * @return Result On success carries ['credentials' => Dto\DashboardEmbeddedCredentials].
	 */
	public function load(
		Dashboard $dashboard,
		int $shareId,
		array $rlsRules,
		int $expSeconds,
		?Dto\User $author = null,
	): Result
	{
		$result = new Result();

		$answer = IntegratorFactory::getInstance()->getDashboardEmbeddedCredentials(
			(int)$dashboard->getExternalId(),
			$rlsRules,
			$expSeconds,
			$author,
		);

		$credentials = $answer->getData();
		if ($answer->hasErrors() || !($credentials instanceof Dto\DashboardEmbeddedCredentials))
		{
			$this->journalRefusal($answer, $dashboard, $shareId);

			return $result->addError($this->createRefusal());
		}

		return $result->setData(['credentials' => $credentials]);
	}

	/**
	 * A guest is an unauthenticated visitor, so the refusal carries the external state and nothing else:
	 * the answer of the instance would let them tell a frozen portal from a broken one. The status stays
	 * in the journal, where support reads it.
	 */
	private function createRefusal(): Error
	{
		return new Error(
			self::ERROR_MESSAGE,
			self::ERROR_CODE,
			[
				'state' => GuestAccessState::ReportUnavailable->value,
			],
		);
	}

	/**
	 * A guest reports nothing but "the report does not open": the two external states are deliberately
	 * coarse and carry no diagnostics. The entry holds what answering such a report needs — which link,
	 * which report and what the instance actually said.
	 *
	 * The link is public by design, so a stream of refused guests is an ordinary situation rather than
	 * an attack, and one entry per link per reason per window is enough to see it.
	 *
	 * An entry the throttle hands out for a group of links (@see GuestRefusalJournalEntry) reports refusals
	 * of links it cannot tell apart. Naming the report and the link of the guest who happened to open it
	 * would report the refusals of the whole group as the refusals of that one link, so such an entry names
	 * neither and says so in its scope, while the item of the row is left empty for the journal to read
	 * as unknown.
	 */
	private function journalRefusal(IntegratorResponse $answer, Dashboard $dashboard, int $shareId): void
	{
		$integratorStatus = $answer->getStatus();

		$entry = $this->journalThrottle->openEntry((string)$integratorStatus, (string)$shareId);
		if ($entry === null)
		{
			return;
		}

		$description = [
			'code' => self::ERROR_CODE,
			'integratorStatus' => $integratorStatus,
			'errors' => array_map(
				static fn(Error $error): string => $error->getMessage(),
				$answer->getErrors(),
			),
			'scope' => $entry->namesLink ? self::SCOPE_LINK : self::SCOPE_UNNAMED_LINKS,
		];

		if ($entry->namesLink)
		{
			$description['dashboardId'] = $dashboard->getId();
			$description['shareId'] = $shareId;
		}

		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => self::AUDIT_TYPE_ID,
			'MODULE_ID' => self::MODULE_ID,
			'ITEM_ID' => $entry->namesLink ? (string)$dashboard->getId() : '',
			'DESCRIPTION' => Json::encode($description),
		]);
	}
}
