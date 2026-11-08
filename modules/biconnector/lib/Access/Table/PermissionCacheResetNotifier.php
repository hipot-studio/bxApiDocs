<?php

namespace Bitrix\BIConnector\Access\Table;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Integration\Superset\SupersetStatusOptionContainer;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;

/**
 * Asks the image to drop the permission cache of this portal when the rights may have changed.
 *
 * The write paths send one message - "the rights may have changed" - and know nothing about keys or
 * transports. Delivery happens after the change is committed and never inside its transaction: the
 * rights are saved in one, and an outbound call has no business there.
 *
 * The notification is an accelerator and not a guarantee. What it buys is that a change is applied at
 * the next request instead of at the end of the safety lifetime the consumer keeps; when it does not
 * arrive, that lifetime is the last line.
 */
final class PermissionCacheResetNotifier
{
	public const AGENT_NAME = 'Bitrix\\BIConnector\\Access\\Table\\PermissionCacheResetNotifier::tailAgent();';

	/**
	 * How often the tail agent wakes while a reset is pending.
	 *
	 * Set by the case it exists for, and that is not the retry: a change committed while another hit held
	 * the lock has no delivery of its own - its background job found the lock busy and left - so the agent
	 * is the only thing that will ever send it, and the wait is what a person sees after saving. A minute
	 * bounds that wait.
	 *
	 * A retry after a failure is bounded by RETRY_WINDOW and not by this interval: a pass inside that
	 * window costs two option reads and no call, so waking more often does not make retries more frequent.
	 * And the agent removes itself once nothing is pending, so a portal that is not resetting anything
	 * carries no minute agent at all.
	 */
	private const TAIL_AGENT_INTERVAL = 60;

	/**
	 * The wait after an attempt that did not deliver, and the only wait there is: an unreachable instance
	 * is a normal state of a box - every update of it passes through this - and every retry is paid for by
	 * whichever portal request happens to run the agent.
	 *
	 * This is not a limit on the number of attempts: the agent keeps going until the reset gets through,
	 * it just goes less often. The time by which a withdrawn right reaches the data is bounded not by
	 * this window but by the safety lifetime the consumer keeps for its records, and five minutes lie
	 * entirely inside that lifetime.
	 */
	private const RETRY_WINDOW = 300;

	private const OPTION_REQUESTED_AT = '~table_permission_cache_reset_requested_at';
	private const OPTION_SENT_AT = '~table_permission_cache_reset_sent_at';
	private const OPTION_ATTEMPTED_AT = '~table_permission_cache_reset_attempted_at';

	private const LOCK_KEY = 'biconnector_table_permission_cache_reset';

	/**
	 * One decision per hit, however many times the write paths call in: one mark and one background job
	 * when the axis is on, one look at the axis when it is off. The handlers of EVENT-01 hang on mass core
	 * events - an LDAP pass deactivates every absent employee in a single hit - so without this a run would
	 * cost hundreds of option writes, or hundreds of capability lookups on a portal that has nothing to
	 * reset at all.
	 */
	private static bool $isNotifiedInThisHit = false;

	/**
	 * Whether the tail agent has a row, asked once per hit. The question costs a query, and the handlers
	 * of EVENT-01 hang on mass core events where the answer cannot change between two triggers of the
	 * same hit: nothing but this class installs or removes that agent.
	 */
	private static ?bool $isTailAgentStanding = null;

	/**
	 * @param bool $force Send even while the axis is off. Only the switching of the axis itself does
	 *                    this: turning the axis off has to reach the consumers, or a long cache goes on
	 *                    restricting people after the restrictions were lifted.
	 */
	public static function notifyChanged(bool $force = false): void
	{
		try
		{
			if (self::$isNotifiedInThisHit && !$force)
			{
				return;
			}

			self::$isNotifiedInThisHit = true;

			if (!$force && !Feature::isTablePermissionsEnabled())
			{
				// With the axis off the consumers have nothing to apply, so there is nothing to drop. The hit is
				// marked as decided before this, so a mass event does not pay for the lookup on every trigger.
				return;
			}

			Option::set('biconnector', self::OPTION_REQUESTED_AT, self::formatTime(self::now()));
			if (!self::isTailAgentStanding())
			{
				self::ensureTailAgentScheduled();
			}

			Application::getInstance()->addBackgroundJob(static function (): void {
				self::deliver();
			});
		}
		catch (\Throwable $exception)
		{
			// The caller is saving rights or handling a core event: a failure to schedule the delivery
			// must not undo that, and must not surface as an error of the save.
			self::logSilenced($exception);
		}
	}

	/**
	 * Runs from the background job of the hit and from the tail agent.
	 */
	public static function deliver(): void
	{
		$requestedAt = self::getOptionTime(self::OPTION_REQUESTED_AT);
		$sentAt = self::getOptionTime(self::OPTION_SENT_AT);
		if ($requestedAt <= $sentAt)
		{
			return;
		}

		// A delivery that got through holds nothing back: the hit that changed the rights sends its own
		// reset, and one hit sends one however many write points it went through. Only an attempt that
		// failed is waited out, and it is recognised by its mark standing later than the mark of the last
		// success - measured from the success alone the wait would never end while the target is
		// unreachable, and every following change would go out again, with its timeout, during a mass
		// update.
		$lastAttemptAt = self::getOptionTime(self::OPTION_ATTEMPTED_AT);
		if ($lastAttemptAt > $sentAt && self::now() - $lastAttemptAt < self::RETRY_WINDOW)
		{
			// The tail agent carries the reset once the wait is over. It goes on waking every minute, but
			// a pass inside this window costs two option reads and no call.
			return;
		}

		$connection = Application::getConnection();
		if (!$connection->lock(self::LOCK_KEY, 0))
		{
			return;
		}

		try
		{
			// Taken before the call and not after it. A change committed while the call is in flight gets a
			// mark earlier than the moment the call returns, so a mark of "now" written afterwards would
			// swallow it: the next pass would read "no tail" and that change would never get its reset.
			$startedAt = self::now();
			Option::set('biconnector', self::OPTION_ATTEMPTED_AT, self::formatTime($startedAt));

			if (!self::isInstanceReady())
			{
				self::onInstanceNotReady($startedAt);

				return;
			}

			$response = IntegratorFactory::getInstance()->clearPermissionCache();
			if ($response->hasErrors())
			{
				// A failure is classified by the same instance status the gate consults: the status code of
				// the response does not carry the difference, because the gate answers with the common inner
				// error, indistinguishable from a refusal of the image itself. Read after the call and not
				// only before it - the instance can leave the ready state while the call is in flight, and
				// in cloud that case must not keep the retry agent alive.
				if (!self::isInstanceReady())
				{
					self::onInstanceNotReady($startedAt);

					return;
				}

				Logger::logErrors($response->getErrors(), [
					'message' => 'Permission cache reset was not delivered',
				]);

				return;
			}

			Option::set('biconnector', self::OPTION_SENT_AT, self::formatTime($startedAt));
			self::logDelivered($response);
		}
		catch (\Throwable $exception)
		{
			self::logSilenced($exception);
		}
		finally
		{
			$connection->unlock(self::LOCK_KEY);
		}
	}

	/**
	 * Writes the answer of the endpoint into the delivery log as it came, and does not read it.
	 *
	 * Nothing here decides anything: whatever the cause, the answer to a reset that did not happen is the
	 * same - send it again - and the marks are moved by the status, not by the body. What the body carries
	 * is diagnostics, and the number of deleted keys in it is the one sign that separates a silent miss
	 * from a reset that had nothing to delete: the endpoint answers success in both cases.
	 *
	 * The portal is where that has to be stored, and in a box it is the only place at all. There the
	 * endpoint lives in the installation of the client, so its own log and metrics are reachable only by
	 * asking that client - that is, only by someone who already knows something went wrong. A channel
	 * nobody reads is not a channel.
	 */
	private static function logDelivered(IntegratorResponse $response): void
	{
		$data = $response->getData();

		Logger::logInfo('Permission cache reset delivered', [
			// An absent answer is not an empty one: it means a provider that did not carry the body over,
			// and in the log that has to look different from an endpoint honestly reporting zero keys.
			'answer' => is_array($data) && array_key_exists('answer', $data)
				? (string)$data['answer']
				: null,
		]);
	}

	/**
	 * Retries what did not get through, and carries the change whose own delivery found the lock busy.
	 * Installed on demand and removes itself once there is nothing pending, so the module gains no
	 * permanent minute agent.
	 */
	public static function tailAgent(): string
	{
		self::deliver();

		// The agent removes itself by answering with an empty name, so the memo of the hit is corrected
		// here: a write point that runs after it in the same hit would otherwise be told the agent stands
		// and leave its own mark with nothing to deliver it.
		self::$isTailAgentStanding = self::isDeliveryPending();

		return self::$isTailAgentStanding ? self::AGENT_NAME : '';
	}

	/**
	 * A request newer than the last delivery. The tail agent keeps itself while this holds and removes
	 * itself once it stops, so the same mark says whether the agent is standing.
	 */
	private static function isDeliveryPending(): bool
	{
		return self::getOptionTime(self::OPTION_REQUESTED_AT) > self::getOptionTime(self::OPTION_SENT_AT);
	}

	/**
	 * "The instance is not ready" means different things in the two hosting modes, and there is no common
	 * reading of it.
	 *
	 * In cloud the cache and the pod live one life: no pod, no Redis, and after unfreezing it comes up
	 * empty - there is genuinely nothing to drop. In box they are separate containers with independent
	 * lifecycles and the Redis data sits on a persistent volume, so the keys survive a restart of the
	 * application, a re-creation of its container and a restart of Redis itself. There an unreachable
	 * instance almost always means a live cache, that is a lost reset - so it is a delivery failure and
	 * the work stays with the retry agent.
	 *
	 * The box path is not rare: every update and every restart of the instance passes through it, and the
	 * update window is chosen by the client and can be long.
	 */
	private static function onInstanceNotReady(float $startedAt): void
	{
		if (SupersetHostMode::isSelfHosted())
		{
			Logger::logWarning(
				[new Error('Permission cache reset was not delivered: the instance does not serve')],
				['message' => 'The box cache outlives an unreachable instance, so the reset is pending'],
			);

			return;
		}

		Option::set('biconnector', self::OPTION_SENT_AT, self::formatTime($startedAt));
	}

	private static function isInstanceReady(): bool
	{
		return (new SupersetStatusOptionContainer())->get() === SupersetInitializer::SUPERSET_STATUS_READY;
	}

	/**
	 * Removed first: AddAgent alone would leave a second copy of the same agent behind whenever one is
	 * already installed with a different interval or next run.
	 */
	private static function ensureTailAgentScheduled(): void
	{
		\CAgent::RemoveAgent(self::AGENT_NAME, 'biconnector');
		\CAgent::AddAgent(
			self::AGENT_NAME,
			'biconnector',
			'N',
			self::TAIL_AGENT_INTERVAL,
			'',
			'Y',
			'',
			100,
			false,
			false,
		);

		self::$isTailAgentStanding = true;
	}

	/**
	 * Asked of the agent table and not derived from the marks.
	 *
	 * Derived from them it answered "the agent stands" for every pending reset, which is the one state where
	 * the answer has to be checked: an agent that went missing while a reset was pending - removed by a run
	 * that cleaned up after itself on a shared portal, or lost with the rest of a restored table - was never
	 * installed again, and the reset stayed pending for as long as nothing else asked for one.
	 */
	private static function isTailAgentStanding(): bool
	{
		if (self::$isTailAgentStanding === null)
		{
			self::$isTailAgentStanding = \CAgent::GetList([], [
				'MODULE_ID' => 'biconnector',
				'NAME' => self::AGENT_NAME,
			])->Fetch() !== false;
		}

		return self::$isTailAgentStanding;
	}

	/**
	 * The marks carry sub-second precision on purpose. Whole seconds cannot tell two changes inside the
	 * same second apart, so a change committed in the very second the delivery started would read as
	 * "already sent" and never get its reset - the same silent miss the back edge exists to prevent,
	 * only narrower.
	 */
	private static function now(): float
	{
		return microtime(true);
	}

	private static function formatTime(float $time): string
	{
		return number_format($time, 4, '.', '');
	}

	private static function getOptionTime(string $name): float
	{
		return max(0.0, (float)Option::get('biconnector', $name, '0'));
	}

	private static function logSilenced(\Throwable $exception): void
	{
		try
		{
			Logger::logWarning([new Error($exception->getMessage())], [
				'message' => 'Permission cache reset notification failed',
			]);
		}
		catch (\Throwable)
		{
			// The only caller is the report of a failure that has already been survived. A logger that
			// throws in turn must not turn that survived failure into a broken user operation, and there
			// is nowhere left to report it to.
		}
	}
}
