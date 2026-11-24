<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\Vibeoffice;

use Bitrix\Disk\Document\Vibeoffice\Box\ConnectionGuard;
use Bitrix\Disk\Document\Vibeoffice\Configuration;
use Bitrix\Disk\Document\Vibeoffice\Connection\DepartingSources;
use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformNamespace;
use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformSource;
use Bitrix\Disk\Document\Vibeoffice\DocumentStateRepository;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Disk\Document\Vibeoffice\Webhook\SignaturePolicyRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

final class SwitchPlatformCommandHandler
{
	public function __construct(
		private readonly ?ConnectionGuard $guard = null,
		private readonly ?Configuration $configuration = null,
		private readonly ?\Closure $clock = null,
		private readonly ?\Closure $beforeCommit = null,
	)
	{
	}

	public function __invoke(SwitchPlatformCommand $command): Result
	{
		$application = Application::getInstance();
		try
		{
			$result = ($this->guard ?? new ConnectionGuard())->withLock(fn(): Result => $this->switch($command));
			if ($result->getErrorCollection()->getErrorByCode('vibeoffice_operation_in_progress') !== null)
			{
				return $this->error('busy');
			}

			return $result;
		}
		catch (Throwable $exception)
		{
			return $this->error('failed', $exception);
		}
		finally
		{
			$application->getManagedCache()->clean('b_option:disk', 'b_option');
		}
	}

	private function switch(SwitchPlatformCommand $command): Result
	{
		$values = [$command->server, $command->keyId, $command->apiSecret, $command->webhookSecret];
		$origin = PlatformNamespace::origin($command->server);
		if (in_array('', $values, true) || $origin === null)
		{
			return $this->error('invalid_settings');
		}
		$configuration = $this->configuration ?? new Configuration();
		if ($configuration->getManagedState() !== null)
		{
			return $this->error('managed_unsupported');
		}
		if ($configuration->hasLocalCredentialOverride())
		{
			return $this->error('local_override');
		}
		if ($configuration->hasSiteCredentialOverride())
		{
			return $this->error('site_override');
		}

		$now = $this->clock !== null ? ($this->clock)() : time();
		$current = [
			$configuration->getServer(), $configuration->getKeyId(),
			$configuration->getApiSecret(), $configuration->getWebhookSecret(),
		];
		$oldSources = (new DepartingSources())->read();
		$sources = array_values(array_filter($oldSources, static fn(PlatformSource $source): bool => $source->until > $now));
		if (($current[3] !== null && hash_equals($current[3], $command->webhookSecret)
			&& PlatformNamespace::origin($current[0] ?? '') !== $origin))
		{
			return $this->error('secret_reused');
		}
		foreach ($sources as $source)
		{
			if (hash_equals($source->webhookSecret(), $command->webhookSecret)
				&& PlatformNamespace::origin($source->server) !== $origin)
			{
				return $this->error('secret_reused');
			}
		}

		$currentNamespace = PlatformNamespace::compute($current[0] ?? '', $current[3] ?? '');
		$newNamespace = PlatformNamespace::compute($command->server, $command->webhookSecret);
		if ($current === $values)
		{
			return $this->success(false, $newNamespace, $sources);
		}

		$binding = null;
		if ($currentNamespace !== null && $newNamespace !== $currentNamespace)
		{
			$pointer = (new SignaturePolicyRepository())->currentPointer();
			foreach ($sources as $source)
			{
				if ($source->namespace === $newNamespace && $source->policyBindingId !== null)
				{
					$binding = $source->policyBindingId;
				}
			}
			$binding ??= SignaturePolicyRepository::noneBinding($pointer['receiveMin']);
			$sources = array_values(array_filter($sources, static fn(PlatformSource $source): bool =>
				$source->namespace !== $newNamespace && $source->namespace !== $currentNamespace));
			$sources[] = new PlatformSource(
				$currentNamespace, $current[0], $current[3], true,
				$pointer['bindingId'], $pointer['receiveMin'], $now + DepartingSources::TTL,
			);
		}
		$encodedSources = $sources === [] ? null : DepartingSources::encode($sources);
		$recordInitial = Option::getRealValue('disk', PlatformNamespace::INITIAL_OPTION, '') === null;
		$connection = Application::getConnection();
		if (DocumentStateRepository::hasOpenTransaction($connection))
		{
			return $this->error('failed');
		}

		Application::getInstance()->getConnectionPool()->useMasterOnly(true);
		try
		{
			$connection->startTransaction();
			try
			{
				if ($recordInitial)
				{
					Option::set('disk', PlatformNamespace::INITIAL_OPTION, $currentNamespace ?? $newNamespace);
				}
				foreach (array_combine(['server', 'key_id', 'api_secret', 'webhook_secret'], $values) as $key => $value)
				{
					Option::set('disk', 'disk_vibeoffice_' . $key, $value);
				}
				if ($encodedSources === null)
				{
					Option::delete('disk', ['name' => DepartingSources::OPTION]);
				}
				else
				{
					Option::set('disk', DepartingSources::OPTION, $encodedSources);
				}
				if ($binding !== null)
				{
					Option::set('disk', SignaturePolicyRepository::BINDING_OPTION, $binding);
				}
				$this->beforeCommit?->__invoke();
				$connection->commitTransaction();
			}
			catch (Throwable $exception)
			{
				$connection->rollbackTransaction();
				throw $exception;
			}
		}
		finally
		{
			Application::getInstance()->getConnectionPool()->useMasterOnly(false);
		}

		return $this->success(true, $newNamespace, $sources);
	}

	/** @param list<PlatformSource> $sources */
	private function success(bool $changed, string $namespace, array $sources): Result
	{
		return (new Result())->setData([
			'changed' => $changed,
			'namespace' => $namespace,
			'departing' => array_map(static fn(PlatformSource $source): array => [
				'namespace' => $source->namespace, 'until' => $source->until,
			], $sources),
		]);
	}

	private function error(string $reason, ?Throwable $exception = null): Result
	{
		if ($reason === 'failed')
		{
			LoggerProvider::get('disk.vibeoffice.maintenance')->error('vibeoffice maintenance', [
				'operation' => 'switch_platform', 'result' => 'error',
				'code' => $exception === null ? 'maintenance_failed' : 'maintenance_exception',
				'stop_reason' => $exception === null ? 'open_transaction' : 'exception',
				'exception_class' => $exception === null ? null : get_class($exception),
			]);
		}

		return (new Result())->addError(new Error('The VibeOffice platform switch was refused.',
			'vibeoffice_switch_' . $reason));
	}
}
