<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Internal\Integration\Timeman\EventHandler;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Pull;
use Bitrix\StaffTrack;
use Bitrix\StaffTrackMobile\Internal\WorkReport\WorkReportCounter;
use Bitrix\StaffTrackMobile\Public\Features\WorkReportCounterFeature;
use Bitrix\TimemanMobile\Public\Features\WorkReportsFeature;

/**
 * Brings a full report written by timeman to the counter of the unsent work report of its owner. The rule of the
 * counter holds in the session of the owner only: there the counter is recalculated after the hit has answered,
 * elsewhere the owner is signalled to sync it. One recalculation and one signal for an owner within a hit.
 */
class FullReportSavedHandler
{
	private const PULL_MODULE_ID = 'stafftrackmobile';
	private const PULL_COMMAND = 'workReportChanged';

	private static ?self $handlerOfHit = null;

	private ?WorkReportCounter $counter = null;

	/** @var array<int, true> */
	private array $handledOwnerIds = [];

	/**
	 * Handles the event timeman sends when a full report is written: the owner of the report goes to the handler
	 * of the hit, the value of the counter is given by the rule.
	 *
	 * @param Event $event
	 * @return void
	 */
	public static function onFullReportSaved(Event $event): void
	{
		self::$handlerOfHit ??= new self();
		self::$handlerOfHit->handle((int)$event->getParameter('userId'));
	}

	/**
	 * Recalculates the counter of the owner after the hit when the owner is the user of the session, otherwise
	 * signals the owner to sync it. The first report of the owner within the hit decides, the rest change nothing.
	 *
	 * @param int $ownerId
	 * @return void
	 */
	public function handle(int $ownerId): void
	{
		if ($ownerId <= 0 || isset($this->handledOwnerIds[$ownerId]) || !$this->isEnabled())
		{
			return;
		}

		$this->handledOwnerIds[$ownerId] = true;

		if ($ownerId === $this->getSessionUserId())
		{
			// Not within the hit: the legacy writers drop the cached report info only after the event.
			$this->defer(fn () => $this->recalculate($ownerId));
		}
		elseif ($this->shouldSignal($ownerId))
		{
			$this->signal($ownerId);
		}
	}

	protected function defer(\Closure $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}

	protected function signal(int $ownerId): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		Pull\Event::add(
			$ownerId,
			[
				'module_id' => self::PULL_MODULE_ID,
				'command' => self::PULL_COMMAND,
				'params' => [],
			],
		);
	}

	/**
	 * Tells without the session of the owner whether the owner may have the counter at all.
	 */
	protected function shouldSignal(int $ownerId): bool
	{
		return
			Feature::isEnabled(WorkReportsFeature::class)
			&& StaffTrack\Feature::isCheckInRolloutEnabled($ownerId)
		;
	}

	protected function isEnabled(): bool
	{
		return (new WorkReportCounterFeature())->isEnabled();
	}

	protected function getCounter(): WorkReportCounter
	{
		return $this->counter ??= new WorkReportCounter();
	}

	/**
	 * The rule counts for the user of the session, who may have changed within the hit since the report was
	 * written. The counter of an owner who no longer has the session, like the counter held by another hit, is left
	 * to the sync of the devices of the owner.
	 */
	private function recalculate(int $ownerId): void
	{
		if ($ownerId !== $this->getSessionUserId() || $this->getCounter()->sync() === null)
		{
			$this->signal($ownerId);
		}
	}

	private function getSessionUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
