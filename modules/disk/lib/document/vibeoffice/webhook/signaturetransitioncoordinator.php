<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\Vibeoffice\Box\ConnectionGuard;
use Bitrix\Disk\Document\Vibeoffice\Box\RegistrationClient;
use Bitrix\Disk\Document\Vibeoffice\Configuration;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Closure;
use Throwable;

final class SignatureTransitionCoordinator
{
	public static function runAgent(): string
	{
		(new self())->run();

		return '\\' . self::class . '::runAgent();';
	}

	public function run(): Result
	{
		try
		{
			$configuration = new Configuration();
			$managed = $configuration->getManagedState();
			$registration = (new OnlyOffice\Configuration())->getStoredCloudRegistrationData();
			if (!is_string($managed['hostId'] ?? null) || $registration === null
				|| !$configuration->hasReadableCredentials())
			{
				return (new Result())->setData(['available' => false]);
			}
			return (new ConnectionGuard(configuration: $configuration))->withLock(function () use (
				$configuration, $registration, $managed,
			): Result {
				$client = new RegistrationClient($registration['serverHost']);
				$clientId = $registration['clientId'];
				$keyId = $configuration->getKeyId();

				return $this->advance($managed['hostId'], static function (
					string $operation, array $parameters,
				) use ($client, $clientId, $keyId): Result {
					return match ($operation) {
						'status' => $client->webhookSignatureStatus($clientId, $keyId),
						'prepare' => $client->prepareWebhookSignatureV2(
							$clientId, $keyId, $parameters['transition_id'], $parameters['expected_epoch'],
						),
						'finalize' => $client->finalizeWebhookSignatureV2(
							$clientId, $keyId, $parameters['transition_id'], $parameters['epoch'],
						),
					};
				});
			});
		}
		catch (Throwable)
		{
			return $this->failure();
		}
	}

	public function advance(string $expectedHost, Closure $exchange): Result
	{
		$policies = new SignaturePolicyRepository();
		try
		{
			$current = $policies->getCurrent();
			$response = $exchange('status', []);
			$status = $response->isSuccess()
				? SignatureStatus::validate($response->getData()['webhookSignature'] ?? null) : null;
			if ($status === null)
			{
				return $current === null || $current['PHASE'] === 'legacy'
					? (new Result())->setData(['available' => false]) : $this->failure();
			}
			if ($status['host_id'] !== $expectedHost)
			{
				return $this->failure();
			}
			$current ??= $policies->bind($status);
			if ($current['PLATFORM_ID'] !== $status['platform_instance_id'] || $current['HOST_ID'] !== $expectedHost
				|| (int)$current['RECEIVE_MIN'] > $status['send_min'])
			{
				return $this->failure();
			}
			if (!$status['rollout_authorized'] && $status['send_min'] === 1)
			{
				return (new Result())->setData(['available' => true, 'pending' => false]);
			}
			$current = $policies->begin($status);
			if ((int)$current['RECEIVE_MIN'] === 1)
			{
				$response = $exchange('prepare', [
					'transition_id' => $current['TRANSITION_ID'], 'expected_epoch' => $status['epoch'],
				]);
				if (!$response->isSuccess())
				{
					return $this->failure();
				}
				$status = SignatureStatus::validate($response->getData()['webhookSignature'] ?? null);
				if ($status === null)
				{
					return $this->failure();
				}
				if ($status['platform_instance_id'] !== $current['PLATFORM_ID']
					|| $status['host_id'] !== $current['HOST_ID']
					|| $status['transition_id'] !== $current['TRANSITION_ID']
					|| $status['epoch'] !== (int)$current['SENDER_EPOCH'] || $status['send_min'] !== 2)
				{
					return $this->failure();
				}
				if ($status['phase'] === 'draining')
				{
					return (new Result())->setData(['available' => true, 'pending' => true]);
				}
				$current = $policies->cutoff($status);
			}
			if ($status['phase'] !== 'complete')
			{
				$response = $exchange('finalize', [
					'transition_id' => $current['TRANSITION_ID'], 'epoch' => (int)$current['SENDER_EPOCH'],
				]);
				if (!$response->isSuccess())
				{
					return $this->failure();
				}
				$status = SignatureStatus::validate($response->getData()['webhookSignature'] ?? null);
				if ($status === null)
				{
					return $this->failure();
				}
			}
			$policies->complete($status);

			return (new Result())->setData(['available' => true, 'pending' => false]);
		}
		catch (Throwable)
		{
			return $this->failure();
		}
	}

	private function failure(): Result
	{
		return (new Result())->addError(new Error(
			'Webhook signature transition could not be completed.', 'webhook_signature_transition_failed',
		));
	}
}
