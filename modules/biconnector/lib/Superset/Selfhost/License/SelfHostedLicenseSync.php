<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorInterface;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Delivery of the license terms to the self-hosted instance: of the extension and of the boxed portal itself.
 *
 * The expected value is the option the licensing layer writes, the delivered one is remembered separately,
 * so delivery is idempotent: the option write event means "the date was written", not "the date changed".
 * Nothing is sent while the portal has no date: an expired license is handled by the instance itself.
 *
 * The term of the box has no write event of its own: its source is the licensing layer, so it goes out on the
 * same occasions as a retry, the agent and the connection of a server.
 */
final class SelfHostedLicenseSync
{
	/**
	 * Public and without a leading backslash: the update of the module registers the agent by this very constant,
	 * and the platform builds the name of an agent without the backslash. A second spelling of the same call would
	 * leave a second row in the schedule - `CAgent` tells rows apart by the exact name.
	 */
	public const AGENT_NAME = 'Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseSync::retryAgent();';

	public const AGENT_INTERVAL = 300;

	/**
	 * Upper bound of the put off between failed attempts. An unreachable instance is not worth a pass every five
	 * minutes, and an instance that came back must not wait for the term longer than an hour.
	 */
	private const RETRY_DELAY_MAX = 3600;

	/**
	 * Words of the verdict of the edition. Kept as words and not as a flag: the mark of the delivery has to tell an
	 * undelivered verdict from a delivered refusal, and an empty option means the first one.
	 */
	private const VERDICT_ALLOWED = 'Y';
	private const VERDICT_DENIED = 'N';

	private const LOCK_KEY = 'biconnector_selfhost_license_expiry_delivery';

	/**
	 * Handler of the option write event. It runs synchronously inside a foreign license activation scenario,
	 * so it only schedules the delivery and never sends anything itself.
	 */
	public static function onExpiryDateOptionSet(): void
	{
		self::runSilently(static function (self $sync): void {
			$sync->forgetShownWarning();
			$sync->scheduleRestoredWorkNotice();
			$sync->scheduleDelivery();
		});
	}

	/**
	 * Second delivery point: the activation could have happened before the server was connected.
	 */
	public static function scheduleDeliveryOnConnect(): void
	{
		self::runSilently(static fn(self $sync) => $sync->scheduleDelivery());
	}

	/**
	 * Delivery during the connection of a server, sent right away instead of being scheduled: until an instance
	 * has a term it refuses every command except issuing a token and this endpoint, so the commands that finish
	 * the connection would get a refusal and the connection would never be saved. Hence the order: connect, hand
	 * over the term, then everything else.
	 *
	 * The local integrator is asked directly: the portal is still in the mode it is leaving, and the factory
	 * would answer with the integrator of that mode.
	 *
	 * @param bool $isServerChanged A server the portal has not delivered to yet answers this call - another address
	 *   or another instance at the same one. The marks tell a delivered term by its value only, so kept marks would
	 *   report the new instance as already holding the term and leave it with none.
	 */
	public static function deliverOnServerConnect(bool $isServerChanged = false): void
	{
		self::runSilently(static function (self $sync) use ($isServerChanged): void {
			if ($isServerChanged)
			{
				self::forgetDeliveredTerms();
			}

			$sync->deliver(IntegratorFactory::getSelfHostedInstance());
		});
	}

	/**
	 * Wipes the license state of the instance, forgets what the portal has delivered to it and applies the switch of
	 * the mode.
	 *
	 * Nothing but a confirmed reset counts - an unreachable instance, a refusal and an exception alike leave the
	 * switch refused, and $applySwitch is not run at all then: an instance that kept its term would go on working
	 * while the portal is already back in the cloud.
	 *
	 * The switch is applied from here, and not by the caller after this returns, so that it happens without the lock
	 * of the delivery being let go: every send goes through that same lock, and a delivery starting between the wipe
	 * and the switch would put the term straight back onto the wiped instance.
	 *
	 * The marks of the delivered terms are forgotten whether the wipe succeeded or not.
	 *
	 * @param callable $applySwitch Applies the switch of the mode; runs only after a confirmed reset.
	 */
	public static function resetInstanceLicenseState(callable $applySwitch): Result
	{
		if (!Application::getConnection()->lock(self::LOCK_KEY, 0))
		{
			$lockResult = new Result();
			$lockResult->addError(new Error('A delivery of the license term is in progress'));

			return $lockResult;
		}

		try
		{
			$result = self::wipeInstanceLicenseState();

			// Dropped whether the answer arrived or not: a request that was sent and timed out leaves the instance
			// wiped, and marks kept would tell the retry agent that nothing is pending.
			self::forgetDeliveredTerms();

			if (!$result->isSuccess())
			{
				return $result;
			}

			Logger::logInfo('License state of the instance is reset');

			// Failures of the switch itself are none of this verdict: the reset did happen, and an exception from here
			// belongs to the caller of the switch as it did before. The lock is let go either way.
			$applySwitch();
		}
		finally
		{
			Application::getConnection()->unlock(self::LOCK_KEY);
		}

		return $result;
	}

	/**
	 * The request of the wipe alone, with one verdict for every way it can fail: a refusal of the instance, an
	 * unexpected status and an exception all answer with an unsuccessful result.
	 *
	 * The local integrator is asked directly: the factory answers with the integrator of the mode of its cache, which
	 * an earlier call of the same request could have filled with the cloud one.
	 */
	private static function wipeInstanceLicenseState(): Result
	{
		$result = new Result();

		try
		{
			$response = IntegratorFactory::getSelfHostedInstance()->resetSelfHostedLicense();
			if ($response->getStatus() === IntegratorResponse::STATUS_OK)
			{
				return $result;
			}

			$errors = $response->getErrors();
			if ($errors === [])
			{
				$errors = [new Error('Instance refused the license reset')];
			}

			Logger::logWarning($errors, [
				'message' => 'License state of the instance is not reset',
				'status' => $response->getStatus(),
			]);
			$result->addErrors($errors);
		}
		catch (\Throwable $exception)
		{
			Logger::logErrors([new Error($exception->getMessage())], [
				'message' => 'License reset of the instance failed',
			]);
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	/**
	 * Retry of a failed attempt and the only way the term of the box reaches the instance: that term has no write
	 * event of its own, so the agent stays in the schedule of a portal in the local mode even when everything is
	 * delivered, and leaves the schedule outside that mode.
	 *
	 * An idle pass reads options and does nothing else: the lock, the server and the instance are reached only after
	 * the check that something is pending.
	 */
	public static function retryAgent(): string
	{
		if (!SupersetHostMode::isSelfHosted())
		{
			return '';
		}

		$sync = new self();
		if ($sync->isRetryDue())
		{
			$sync->deliver();
		}

		return self::AGENT_NAME;
	}

	private static function runSilently(callable $job): void
	{
		try
		{
			$job(new self());
		}
		catch (\Throwable $exception)
		{
			self::logSilencedFailure($exception);
		}
	}

	/**
	 * Callers run inside foreign scenarios, the activation of a license and the connection of a server, so
	 * nothing escapes from here, the logging included.
	 */
	private static function logSilencedFailure(\Throwable $exception): void
	{
		try
		{
			Logger::logWarning([new Error($exception->getMessage())], [
				'message' => 'Self-hosted license expiry date delivery was not started',
			]);
		}
		catch (\Throwable)
		{
		}
	}

	private function scheduleDelivery(): void
	{
		if (!SupersetHostMode::isSelfHosted() || !$this->isDeliveryPending())
		{
			return;
		}

		self::installAgent();

		// The mode is asked again inside the job: background jobs run at the end of the request, and a request that
		// switched the portal back to the cloud runs this one already there - the cloud integrator would refuse the
		// term locally and the refusal would put off retries on a portal that has no retry agent left.
		Application::getInstance()->addBackgroundJob(static function (): void {
			if (!SupersetHostMode::isSelfHosted())
			{
				return;
			}

			(new self())->deliver();
		});
	}

	/**
	 * The lock is two queries and creating the integrator looks the server up in a table of its own, so neither is
	 * taken until it is known that there is something to send.
	 *
	 * Nothing left to send releases the put off of the retries as a delivery does: a value that was refused can also
	 * disappear - the term is reset or a new one is rejected by the reader - and then the put off left behind would
	 * hold back the next term for up to an hour.
	 */
	private function deliver(?IntegratorInterface $integrator = null): void
	{
		if (!$this->isDeliveryPending())
		{
			$this->forgetPutOffRetry();

			return;
		}

		if (!Application::getConnection()->lock(self::LOCK_KEY, 0))
		{
			return;
		}

		try
		{
			$resolved = $integrator ?? IntegratorFactory::getInstance();
			$isEditionDelivered = $this->deliverPendingEditionVerdict($resolved);
			$isExtensionDelivered = $this->deliverPendingExpiryDate($resolved);
			$isBoxDelivered = $this->deliverPendingBoxExpiryDate($resolved);

			$this->rememberDeliveryOutcome($isEditionDelivered && $isExtensionDelivered && $isBoxDelivered);
		}
		catch (\Throwable $exception)
		{
			$this->rememberDeliveryOutcome(false);
			Logger::logErrors([new Error($exception->getMessage())], [
				'message' => 'Self-hosted license expiry date delivery failed',
			]);
		}
		finally
		{
			Application::getConnection()->unlock(self::LOCK_KEY);
		}
	}

	/**
	 * False only when the instance refused the term: nothing left to send counts as delivered.
	 */
	private function deliverPendingExpiryDate(IntegratorInterface $integrator): bool
	{
		$expected = $this->readExpectedTimestamp();
		$delivered = $this->readDeliveredTimestamp();
		if ($expected <= 0 || $expected === $delivered)
		{
			return true;
		}

		$response = $integrator->setSelfHostedLicenseExpiration(
			DateTime::createFromTimestamp($expected),
		);

		if ($response->getStatus() === IntegratorResponse::STATUS_OK)
		{
			Option::set('biconnector', LicenseOption::DELIVERED_EXPIRY_DATE, (string)$expected);
			Logger::logInfo('Self-hosted license expiry date delivered', [
				'expiryTimestamp' => $expected,
				'previousTimestamp' => $delivered,
			]);

			return true;
		}

		$errors = $response->getErrors();
		Logger::logWarning(
			$errors === [] ? [new Error('Instance refused the self-hosted license expiry date')] : $errors,
			[
				'message' => 'Self-hosted license expiry date is not delivered',
				'expiryTimestamp' => $expected,
				'deliveredTimestamp' => $delivered,
				'status' => $response->getStatus(),
			],
		);

		return false;
	}

	/**
	 * Verdict of the edition, delivered with the same idempotency as the terms: the mark tells a delivered verdict
	 * by its value, so an unchanged one costs no request.
	 *
	 * Sent in both directions and never left out: an instance that has not been told is an instance that does not
	 * know whether it may work, and it answers that the same way it answers a term it never got.
	 */
	private function deliverPendingEditionVerdict(IntegratorInterface $integrator): bool
	{
		$expected = self::readExpectedEditionVerdict();
		if ($expected === $this->readDeliveredEditionVerdict())
		{
			return true;
		}

		$response = $integrator->setSelfHostedEditionVerdict($expected === self::VERDICT_ALLOWED);

		if ($response->getStatus() === IntegratorResponse::STATUS_OK)
		{
			Option::set('biconnector', LicenseOption::DELIVERED_EDITION_VERDICT, $expected);
			Logger::logInfo('Edition verdict delivered', [
				'verdict' => $expected,
				'previousVerdict' => $this->readDeliveredEditionVerdict(),
			]);

			return true;
		}

		$errors = $response->getErrors();
		Logger::logWarning(
			$errors === [] ? [new Error('Instance refused the edition verdict')] : $errors,
			[
				'message' => 'Edition verdict is not delivered',
				'verdict' => $expected,
				'status' => $response->getStatus(),
			],
		);

		return false;
	}

	/**
	 * Delivered the same way and with the same idempotency as the term of the extension, but told apart: the
	 * instance keeps it as a value of its own and blocks itself by it with its own page.
	 */
	private function deliverPendingBoxExpiryDate(IntegratorInterface $integrator): bool
	{
		$expected = BoxLicenseTerm::getExpiryTimestamp() ?? 0;
		$delivered = $this->readDeliveredBoxTimestamp();
		if ($expected <= 0 || $expected === $delivered)
		{
			return true;
		}

		$response = $integrator->setBoxLicenseExpiration(
			DateTime::createFromTimestamp($expected),
		);

		if ($response->getStatus() === IntegratorResponse::STATUS_OK)
		{
			Option::set('biconnector', LicenseOption::DELIVERED_BOX_EXPIRY_DATE, (string)$expected);
			Logger::logInfo('Box license expiry date delivered', [
				'expiryTimestamp' => $expected,
				'previousTimestamp' => $delivered,
			]);

			return true;
		}

		$errors = $response->getErrors();
		Logger::logWarning(
			$errors === [] ? [new Error('Instance refused the box license expiry date')] : $errors,
			[
				'message' => 'Box license expiry date is not delivered',
				'expiryTimestamp' => $expected,
				'deliveredTimestamp' => $delivered,
				'status' => $response->getStatus(),
			],
		);

		return false;
	}

	/**
	 * Only the agent obeys the put off of the refused attempts - the write of the term and the connection of a server
	 * deliver right away, because both mean that something has just changed.
	 */
	/**
	 * The edition gate of the chain and nothing else: what the portal shows itself and what the instance is told
	 * must be one answer, so both read the same gate.
	 */
	private static function readExpectedEditionVerdict(): string
	{
		return SupersetHostMode::checkSelfHostedEdition()->isSuccess()
			? self::VERDICT_ALLOWED
			: self::VERDICT_DENIED
		;
	}

	private function readDeliveredEditionVerdict(): string
	{
		return (string)Option::get('biconnector', LicenseOption::DELIVERED_EDITION_VERDICT, '');
	}

	private function isRetryDue(): bool
	{
		return $this->readRetryAfter() <= time();
	}

	private function rememberDeliveryOutcome(bool $isDelivered): void
	{
		if ($isDelivered)
		{
			$this->forgetPutOffRetry();

			return;
		}

		$delay = min(max(self::AGENT_INTERVAL, $this->readRetryDelay() * 2), self::RETRY_DELAY_MAX);

		Option::set('biconnector', LicenseOption::DELIVERY_RETRY_DELAY, (string)$delay);
		Option::set('biconnector', LicenseOption::DELIVERY_RETRY_AFTER, (string)(time() + $delay));
	}

	/**
	 * Both marks go away together: the instance keeps the two terms in one state, and the reset wipes them in one
	 * request.
	 */
	private static function forgetDeliveredTerms(): void
	{
		Option::delete('biconnector', ['name' => LicenseOption::DELIVERED_EXPIRY_DATE]);
		Option::delete('biconnector', ['name' => LicenseOption::DELIVERED_BOX_EXPIRY_DATE]);
		Option::delete('biconnector', ['name' => LicenseOption::DELIVERED_EDITION_VERDICT]);
	}

	private function forgetPutOffRetry(): void
	{
		if ($this->readRetryAfter() === 0 && $this->readRetryDelay() === 0)
		{
			return;
		}

		Option::set('biconnector', LicenseOption::DELIVERY_RETRY_DELAY, '0');
		Option::set('biconnector', LicenseOption::DELIVERY_RETRY_AFTER, '0');
	}

	private function readRetryAfter(): int
	{
		return max(0, (int)Option::get('biconnector', LicenseOption::DELIVERY_RETRY_AFTER, '0'));
	}

	private function readRetryDelay(): int
	{
		return max(0, (int)Option::get('biconnector', LicenseOption::DELIVERY_RETRY_DELAY, '0'));
	}

	/**
	 * True while at least one of the two terms has not reached the instance: the retry agent lives until both are.
	 */
	private function isDeliveryPending(): bool
	{
		if (self::readExpectedEditionVerdict() !== $this->readDeliveredEditionVerdict())
		{
			return true;
		}

		$expected = $this->readExpectedTimestamp();
		if ($expected > 0 && $expected !== $this->readDeliveredTimestamp())
		{
			return true;
		}

		$expectedBox = BoxLicenseTerm::getExpiryTimestamp() ?? 0;

		return $expectedBox > 0 && $expectedBox !== $this->readDeliveredBoxTimestamp();
	}

	/**
	 * Re-read on every call: the license is remembered in a static instance, and background jobs and agents live
	 * long enough for the remembered value to go stale.
	 */
	private function readExpectedTimestamp(): int
	{
		SelfHostedLicense::reset();

		return SelfHostedLicense::getInstance()->getExpiryDate()?->getTimestamp() ?? 0;
	}

	private function readDeliveredTimestamp(): int
	{
		return max(0, (int)Option::get('biconnector', LicenseOption::DELIVERED_EXPIRY_DATE, '0'));
	}

	private function readDeliveredBoxTimestamp(): int
	{
		return max(0, (int)Option::get('biconnector', LicenseOption::DELIVERED_BOX_EXPIRY_DATE, '0'));
	}

	/**
	 * A new date makes the already shown warnings meaningless: without the reset the banner would stay after
	 * a renewal and neither window before the new date would produce a warning at all.
	 *
	 * Nothing is written when there is nothing to forget: every write of an option drops the cached block of the
	 * options of the module.
	 */
	private function forgetShownWarning(): void
	{
		$shownWarnings = [LicenseOption::EXPIRY_WARNING_SHOWN, LicenseOption::EARLY_WARNING_SHOWN];
		foreach ($shownWarnings as $option)
		{
			if (Option::get('biconnector', $option, 'N') !== 'N')
			{
				Option::set('biconnector', $option, 'N');
			}
		}
	}

	/**
	 * Put off to the end of the request for the same reason as the delivery: notifying administrators inside the
	 * foreign activation scenario would make it wait for messages to be written.
	 */
	private function scheduleRestoredWorkNotice(): void
	{
		Application::getInstance()->addBackgroundJob(static function (): void {
			(new SelfHostedLicenseNotifier())->notifyAboutRestoredWork();
		});
	}

	public static function installAgent(): void
	{
		AgentInstaller::install(self::AGENT_NAME, self::AGENT_INTERVAL);
	}

	public static function removeAgent(): void
	{
		AgentInstaller::remove(self::AGENT_NAME);
	}
}
