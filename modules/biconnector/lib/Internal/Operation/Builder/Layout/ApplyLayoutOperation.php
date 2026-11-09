<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DashboardGuard;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DashboardWriteLock;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftOnlyRule;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftPolicy;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\SetContentOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

final class ApplyLayoutOperation
{
	public const MAX_WRITE_BYTES = 2097152;

	private readonly DashboardGuard $guard;
	private readonly DraftOnlyRule $draftRule;
	private readonly LayoutSource $source;
	private readonly LayoutBatch $batch;
	private readonly ChartAdmission $admission;
	private readonly LayoutWriteEncoder $encoder;
	private ?DashboardWriteLock $lock;

	public function __construct(
		?DashboardGuard $guard = null,
		?DraftOnlyRule $draftRule = null,
		?LayoutSource $source = null,
		?LayoutBatch $batch = null,
		?ChartAdmission $admission = null,
		?DashboardWriteLock $lock = null,
		?LayoutWriteEncoder $encoder = null,
	)
	{
		$this->guard = $guard ?? new DashboardGuard();
		$this->draftRule = $draftRule ?? new DraftOnlyRule();
		$this->source = $source ?? new LayoutSource();
		$this->batch = $batch ?? new LayoutBatch();
		$this->admission = $admission ?? new ChartAdmission($this->guard);
		$this->lock = $lock;
		$this->encoder = $encoder ?? new LayoutWriteEncoder();
	}

	public function execute(ApplyLayoutCommand $command, Actor $actor, DraftPolicy $policy): Result
	{
		$validated = $this->batch->validate($command->operations);
		if (!$validated->isSuccess())
		{
			return $validated;
		}
		if (!$command->dryRun && ($command->version === null || $command->version === ''))
		{
			return Refusal::error(FailureCode::InvalidArgument, 'A version is required when applying the layout.')
				->setData(['reason' => 'version_required'])
			;
		}
		$loaded = $this->guard->forWrite($command->dashboardId, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}
		$dashboard = $loaded->getData()['dashboard'];
		if (!$command->dryRun)
		{
			$draft = $this->draftRule->check($dashboard, $policy);
			if (!$draft->isSuccess())
			{
				return $draft;
			}
		}
		$addedIds = $validated->getData()['addedChartIds'];
		foreach ($validated->getData()['operations'] as $index => $operation)
		{
			if ($operation['operation'] !== 'add_chart_to_new_row')
			{
				continue;
			}
			$admitted = $this->admission->admit([$operation['chartId']], $actor);
			if (!$admitted->isSuccess())
			{
				return $this->admissionFailure($admitted, [$index => $operation]);
			}
		}

		$work = fn(): Result => $this->applyFresh(
			$command,
			$actor,
			$policy,
			[
				'externalId' => (int)$dashboard->getExternalId(),
				'publishedHere' => $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY,
				'operations' => $validated->getData()['operations'],
				'addedIds' => $addedIds,
			],
		);
		if ($command->dryRun)
		{
			return $work();
		}
		$this->lock ??= new DashboardWriteLock();

		return $this->lock->withLock($command->dashboardId, $work) ?? $this->conflict('busy', null);
	}

	private function applyFresh(ApplyLayoutCommand $command, Actor $actor, DraftPolicy $policy, array $context): Result
	{
		$read = $this->source->read($context['externalId'], $actor, 'layout.apply');
		if (!$read->isSuccess())
		{
			return $read;
		}
		$source = $read->getData();
		$layout = DashboardLayout::fromPositions($source['positions']);
		$limits = $layout->limitProblems();
		if ($limits !== [])
		{
			return LayoutBatch::layoutLimit($limits[0]);
		}
		$membershipProblem = LayoutSource::getMembershipProblem($layout, $source['chartIds']);
		if ($membershipProblem === 'chart_membership_unknown')
		{
			return Refusal::error(FailureCode::Unavailable, 'Dashboard source: chart_membership_unknown.')
				->setData(['reason' => $membershipProblem])
			;
		}
		if ($membershipProblem === 'incomplete_layout')
		{
			return Refusal::error(FailureCode::DomainRule, 'Dashboard source: incomplete_layout.')
				->setData(['rule' => $membershipProblem])
			;
		}
		$metadata = $source['jsonMetadata'];
		$version = $layout->version($metadata['native_filter_configuration'] ?? []);
		if ($command->version !== null && $command->version !== $version)
		{
			return $this->conflict('stale', $version);
		}
		$oldIds = SetContentOperation::extractChartIdsFromPositions($layout->toPositions());
		$batch = $this->batch->run(
			$layout,
			$context['operations'],
			$version,
			function () use ($context, $oldIds, $metadata): Result {
				if ($context['addedIds'] === [])
				{
					return new Result();
				}

				return $this->admissionFailure(
					$this->admission->checkInLayout($context['addedIds'], $oldIds, $metadata),
					$context['operations'],
				);
			},
		);
		if (!$batch->isSuccess())
		{
			return $batch;
		}
		$data = $batch->getData();
		$final = $data['layout'];
		$newIds = SetContentOperation::extractChartIdsFromPositions($final->toPositions());
		$metadata = $this->admission->rescope($metadata, $oldIds, $newIds);
		$answer = GetLayoutOperation::describe(
			$command->dashboardId,
			$source['published'] || $context['publishedHere'],
			$final,
			$metadata,
		);
		$changed = $answer['version'] !== $version;
		if (!$changed)
		{
			return Refusal::error(FailureCode::InvalidArgument, 'The batch makes no layout changes.')
				->setData(['reason' => 'no_changes'])
			;
		}
		$answer += [
			'dryRun' => $command->dryRun,
			'changed' => $changed,
			'baseVersion' => $version,
			'summary' => $data['summary'],
		];
		$result = GetLayoutOperation::answer($answer);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$encoded = $this->encoder->encode($metadata, $final->toPositions());
		if (strlen($encoded) > self::MAX_WRITE_BYTES)
		{
			return Refusal::error(FailureCode::DomainRule, 'The layout write exceeds the byte limit.')
				->setData(['limit' => self::MAX_WRITE_BYTES, 'value' => strlen($encoded)])
			;
		}
		if ($command->dryRun)
		{
			return $result;
		}
		$draft = $this->draftRule->recheck($command->dashboardId, $policy, $source['published']);
		if (!$draft->isSuccess())
		{
			return $draft;
		}

		try
		{
			$response = IntegratorFactory::getInstance()->updateDashboard($context['externalId'], [
				'json_metadata' => $encoded,
			]);
		}
		catch (\Exception)
		{
			return $this->unknownWrite();
		}
		if ($response->hasErrors() || $response->getStatus() >= 400)
		{
			return $this->unknownWrite();
		}

		return $result;
	}

	private function admissionFailure(Result $result, array $operations): Result
	{
		if ($result->isSuccess() || ($result->getErrors()[0]->getCode() ?? null) !== FailureCode::InvalidArgument->value)
		{
			return $result;
		}
		foreach ($operations as $index => $operation)
		{
			if ($operation['operation'] === 'add_chart_to_new_row')
			{
				$reason = $result->getData()['reason'];

				return Refusal::error(FailureCode::InvalidArgument, "operations[{$index}] add_chart_to_new_row: {$reason}.")
					->setData(['index' => $index, 'operation' => 'add_chart_to_new_row', 'reason' => $reason])
				;
			}
		}

		return $result;
	}

	private function conflict(string $reason, ?string $version): Result
	{
		return Refusal::error(FailureCode::VersionConflict, 'Layout version conflict: ' . $reason . '. Re-read the layout.')
			->setData(['reason' => $reason, 'version' => $version])
		;
	}

	private function unknownWrite(): Result
	{
		return Refusal::error(FailureCode::Unavailable, 'The write outcome is unknown; re-read the layout before retrying.')
			->setData(['reason' => 'write_unknown'])
		;
	}
}
