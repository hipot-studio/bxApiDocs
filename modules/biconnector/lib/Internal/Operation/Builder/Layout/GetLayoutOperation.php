<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DashboardGuard;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class GetLayoutOperation
{
	public const MAX_ANSWER_BYTES = 65536;

	private readonly DashboardGuard $guard;
	private readonly LayoutSource $source;

	public function __construct(?DashboardGuard $guard = null, ?LayoutSource $source = null)
	{
		$this->guard = $guard ?? new DashboardGuard();
		$this->source = $source ?? new LayoutSource();
	}

	public function execute(int $dashboardId, Actor $actor): Result
	{
		$loaded = $this->guard->forView($dashboardId, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}
		$dashboard = $loaded->getData()['dashboard'];
		$read = $this->source->read((int)$dashboard->getExternalId(), $actor, 'layout.get');
		if (!$read->isSuccess())
		{
			return $read;
		}
		$data = $read->getData();
		$layout = DashboardLayout::fromPositions($data['positions']);
		$limits = $layout->limitProblems();
		if ($limits !== [])
		{
			return LayoutBatch::layoutLimit($limits[0]);
		}

		$answer = self::describe(
			$dashboardId,
			$data['published'] || $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY,
			$layout,
			$data['jsonMetadata'],
		);
		$membershipProblem = LayoutSource::getMembershipProblem($layout, $data['chartIds']);
		if ($membershipProblem !== null)
		{
			$answer['warnings'][] = ['code' => $membershipProblem, 'componentId' => 'ROOT_ID'];
		}

		return self::answer($answer);
	}

	public static function describe(int $id, bool $published, DashboardLayout $layout, array $metadata): array
	{
		return [
			'id' => $id,
			'published' => $published,
			'version' => $layout->version($metadata['native_filter_configuration'] ?? []),
			'layout' => $layout->view(),
			'warnings' => $layout->warningProblems(),
		];
	}

	public static function answer(array $data): Result
	{
		$bytes = strlen(Json::encode($data));
		if ($bytes > self::MAX_ANSWER_BYTES)
		{
			return Refusal::error(FailureCode::DomainRule, 'The layout answer exceeds the byte limit.')
				->setData(['limit' => self::MAX_ANSWER_BYTES, 'value' => $bytes])
			;
		}

		return (new Result())->setData($data);
	}
}
