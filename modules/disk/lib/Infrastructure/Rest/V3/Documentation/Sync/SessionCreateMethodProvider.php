<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\SessionDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncAccessDeniedException;
use Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync\StrictRequest;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Documentation of disk.sync.session.create.
 */
final class SessionCreateMethodProvider extends SyncMethodProvider
{
	private const PARAMETER_HISTORY_DEPTH_DAYS = 'historyDepthDays';
	private const PARAMETER_PLANNED_POLL_INTERVAL_SECONDS = 'plannedPollIntervalSeconds';

	protected function getItemDto(): Dto
	{
		return SessionDto::create();
	}

	protected function getRefusals(): array
	{
		$limits = new SyncLimits();

		return [
			new FeatureNotSupportedException(),
			new SyncAccessDeniedException(),
			StrictRequest::refusal(
				self::PARAMETER_PLANNED_POLL_INTERVAL_SECONDS,
				'DISK_SYNC_REQUEST_POLL_INTERVAL_OUT_OF_BOUNDS',
				[
					'#MIN#' => (string)$limits->getPollIntervalMinSeconds(),
					'#MAX#' => (string)$limits->getPollIntervalMaxSeconds(),
				],
			),
		];
	}

	protected function getRequestBody(): array
	{
		return [
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'required' => [
							self::PARAMETER_HISTORY_DEPTH_DAYS,
							self::PARAMETER_PLANNED_POLL_INTERVAL_SECONDS,
						],
						'properties' => [
							self::PARAMETER_HISTORY_DEPTH_DAYS => [
								'type' => 'integer',
								'description' =>
									'How far back the history of files is asked for, in days. The portal '
									. 'may serve less than was asked for and says how much in '
									. 'effectiveHistoryDepthDays, so a depth above what it serves is not '
									. 'an error.'
								,
							],
							self::PARAMETER_PLANNED_POLL_INTERVAL_SECONDS => [
								'type' => 'integer',
								'description' =>
									'How often the client intends to come back for changes, in seconds. '
									. 'Checked against the bounds disk.sync.capabilities.get publishes '
									. 'and then only recorded - nothing on the server is timed by it. A '
									. 'value outside the bounds is refused rather than trimmed.'
								,
							],
						],
					],
				],
			],
		];
	}

	protected function getTitle(): ?string
	{
		return 'Open a cycle of synchronisation';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Fixes everything the initial fetch is read against and hands back the two positions the
			client works from.

			A session belongs to a pair - the user and the application calling on their behalf - and
			not to the user alone. Two applications of the same user hold two independent sessions and
			cannot use each other's positions.

			`snapshotToken` is the position of the initial fetch and stops being accepted at
			`snapshotTokenExpiresAt`; a fetch that did not finish by then is started again with a new
			session rather than stitched onto the old one. `cursor` is the position in the journal of
			changes. It is handed out together with the session because the two are read in turn from
			the very beginning: what the fetch has not reached, the journal already keeps. The cursor
			outlives the session it came with - it belongs to the pair, and creating a session again
			does not annul it - but it does not outlive the retention of the journal counted from that
			creation, and creating a session again does not extend it either. Every position of one
			session stops being accepted at the same moment, however far it has been read.

			Creating a session again while one is live is a normal thing to do, and a client changing
			its polling step does exactly that. The previous session is replaced and its fetch token
			stops matching; the cursor of the previous session keeps working, so a change of session is
			not a re-read of the journal. A client that means to go on reading for longer than the
			retention of the journal has to do this before the cursor of its current session expires -
			disk.sync.changes.list says when that is and how it is done without reconciling anything.

			`effectiveHistoryDepthDays` is always answered, including when it equals what was asked for:
			a client handed less than it asked for without being told would take the shorter answer for
			the whole of its history.

			`approximateObjectCount` and `approximateObjectCountComplete` are a lower bound of what the
			initial fetch is about to deliver and whether that bound is the whole of it. Both are absent
			from the answer - not null - when no storage of the user could be counted without checking
			rights per object: a client has to be able to tell "the portal cannot estimate this cheaply"
			from "there is nothing", and a zero would say the second.
			TEXT;
	}
}
