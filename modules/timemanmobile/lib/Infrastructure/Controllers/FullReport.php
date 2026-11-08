<?php

namespace Bitrix\TimemanMobile\Infrastructure\Controllers;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Infrastructure\Controller;
use Bitrix\Timeman\V2\Public\Dto\FullReport\FullReport as FullReportDto;
use Bitrix\Timeman\V2\Public\Dto\FullReport\UserReports;
use Bitrix\Timeman\V2\Public\Dto\FullReport\UserReportsPage;
use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;
use Bitrix\TimemanMobile\Infrastructure\Controllers\Trait\ReportSettingsPayloadTrait;
use Bitrix\TimemanMobile\Internal\Integration\Timeman\FullReportMark;
use Bitrix\TimemanMobile\Internal\Integration\Timeman\FullReportSend;

class FullReport extends Base
{
	use ReportSettingsPayloadTrait;

	private const ITEM_TYPE_USER = 'WorkReportUser';
	private const ITEM_TYPE_ROW = 'WorkReportRow';

	/**
	 * The decision of the AI reports layer travels as an action of its own, and the mark of the old scheme
	 * names which one. The unconfirmed mark is left out: that layer has no action writing it, so a report
	 * returned to the unconfirmed state stays with the writer of the old scheme.
	 */
	private const AI_LAYER_MARK_ACTIONS = [
		'G' => 'approve',
		'B' => 'disapprove',
		'N' => 'reject',
	];

	/**
	 * The client sends the payload as JSON, and only this filter unpacks such a body into action parameters.
	 * The module base controller is not a JsonController, and the other actions here declare no parameters of
	 * their own: they forward to timeman, where the same filter is already in place.
	 */
	public function configureActions(): array
	{
		return [
			'getDiscussionUrl' => [
				'+prefilters' => [
					new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
				],
			],
			'setMark' => [
				'+prefilters' => [
					new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
				],
			],
			'submit' => [
				'+prefilters' => [
					new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
				],
			],
		];
	}

	/**
	 * @ajaxAction timemanmobile.FullReport.getUserReports
	 */
	public function getUserReportsAction(): ?array
	{
		$result = $this->forward(
			Controller\FullReport::class,
			'getUserReports',
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		return [
			'items' => $result ? $result->toArray() : [],
		];
	}

	/**
	 * @ajaxAction timemanmobile.FullReport.getReportToSend
	 */
	public function getReportToSendAction(): ?array
	{
		$result = $this->forward(
			Controller\FullReport::class,
			'getReportToSend',
			[
				'force' => true,
				'withDraftFallback' => true,
			],
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$payload = $result?->toArray();
		if ($payload === null)
		{
			return null;
		}

		return [
			'report' => $payload,
			'settings' => $this->getReportSettingsPayload(),
		];
	}

	/**
	 * @ajaxAction timemanmobile.FullReport.getReportSettings
	 */
	public function getReportSettingsAction(): array
	{
		return [
			'settings' => $this->getReportSettingsPayload(),
		];
	}

	/**
	 * @ajaxAction timemanmobile.FullReport.getSubordinateUserReports
	 */
	public function getSubordinateUserReportsAction(): ?array
	{
		$result = $this->forward(
			Controller\FullReport::class,
			'getSubordinateUserReports',
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$settings = $this->getReportSettingsPayload();

		if (!$result instanceof UserReportsPage)
		{
			return [
				'items' => [],
				'hasMore' => false,
				'nextOffset' => null,
				'settings' => $settings,
			];
		}

		return [
			'items' => $this->flattenUserReportsPage($result),
			'hasMore' => $result->hasMore,
			'nextOffset' => $result->nextOffset,
			'settings' => $settings,
		];
	}

	/**
	 * @ajaxAction timemanmobile.FullReport.getDiscussionUrl
	 */
	public function getDiscussionUrlAction(
		#[PositiveNumber]
		int $reportId,
	): array
	{
		// A missing entry and an entry the user may not read are both answered with null by design.
		return [
			'url' => (new FullReportProvider())->getDiscussionUrl(
				$reportId,
				(int)$this->getCurrentUser()?->getId(),
			),
		];
	}

	/**
	 * Manager decision. Every decision of the mobile client comes here, whatever scheme the client itself
	 * reads, because the scheme a decision belongs to is the one the OWNER of the report reads: the AI
	 * reports setting is answered per department, so a manager and his subordinate may well read different
	 * ones. Written into the scheme of the manager, a decision on a report of the old scheme would leave
	 * the livefeed entry of that report and its author untouched.
	 *
	 * @ajaxAction timemanmobile.FullReport.setMark
	 */
	public function setMarkAction(
		#[PositiveNumber]
		int $reportId,
		string $mark,
	): bool
	{
		$aiLayerAction = $this->aiLayerActionFor($reportId, $mark);
		if ($aiLayerAction !== null)
		{
			// The decision of that layer is left to the very action of timeman it writes it with, rights
			// and all: routing the write here must not turn into a second implementation of it.
			$this->forward(Controller\FullReport::class, $aiLayerAction, ['reportId' => $reportId]);

			return $this->errorCollection->isEmpty();
		}

		$result = FullReportMark::set($reportId, $mark, (int)$this->getCurrentUser()?->getId());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * Report sent by the old scheme. The client comes here while the AI reports layer is off: there the send
	 * must reach the legacy row, the livefeed entry and the manager the same way the web path sends it.
	 *
	 * The report id is optional on purpose: the first send of a period has no saved report, and both the web
	 * path and `timeman.V2.FullReport.submit` create the row in that case.
	 *
	 * @ajaxAction timemanmobile.FullReport.submit
	 */
	public function submitAction(
		int $reportId = 0,
		string $reportText = '',
	): bool
	{
		$result = FullReportSend::send($reportId, $reportText);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * The action of the AI reports layer this decision belongs to, and none when it belongs to the old
	 * scheme. A mark the layer cannot write and a report nobody owns both stay with the old scheme: it
	 * answers an unknown mark and a missing report the way the client already reads them, and nothing is
	 * asked about the settings of a user who does not exist.
	 */
	private function aiLayerActionFor(int $reportId, string $mark): ?string
	{
		$action = self::AI_LAYER_MARK_ACTIONS[$mark] ?? null;
		if ($action === null)
		{
			return null;
		}

		$ownerId = FullReportMark::ownerIdOf($reportId);

		return $ownerId > 0 && $this->isAiReportsEnabledFor($ownerId) ? $action : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function flattenUserReportsPage(UserReportsPage $page): array
	{
		$items = [];
		/** @var UserReports $userReports */
		foreach ($page->items as $userReports)
		{
			$userId = $userReports->userId;

			$items[] = [
				'id' => 'user-' . $userId,
				'itemType' => self::ITEM_TYPE_USER,
				'userId' => $userId,
				'user' => $userReports->user?->toArray() ?? ['id' => $userId],
			];

			/** @var FullReportDto $report */
			foreach ($userReports->reports as $report)
			{
				$items[] = [
					'id' => 'report-' . $report->id,
					'itemType' => self::ITEM_TYPE_ROW,
					'userId' => $report->userId,
					'report' => $report->toArray(),
				];
			}
		}

		return $items;
	}
}
