<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Disk\Integration\AiAssistant\Service\DiskMcpService;
use Bitrix\Main\Application;
use Bitrix\Main\Authentication\Context as UserAuthenticationContext;
use InvalidArgumentException;

abstract class BaseDiskTool extends ToolContract
{
	private const USER_SESSION_KEYS = ['SESS_AUTH', 'SESS_OPERATIONS'];

	private ?DiskMcpService $diskService = null;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);
	}

	public function run(int $userId, ...$args): array
	{
		if ($userId <= 0)
		{
			throw new InvalidArgumentException('User id must be greater than zero.');
		}

		$context = $this->captureCurrentUserContext();

		try
		{
			$user = new \CUser();
			$authContext = (new UserAuthenticationContext())->setUserId($userId);
			if ($user->UpdateSessionData($authContext) === false)
			{
				throw new InvalidArgumentException('User id must reference an active user.');
			}
			$GLOBALS['USER'] = $user;

			return parent::run($userId, ...$args);
		}
		finally
		{
			$this->restoreCurrentUserContext($context);
		}
	}

	public function canList(int $userId): bool
	{
		return $userId > 0;
	}

	public function canRun(int $userId): bool
	{
		return $userId > 0;
	}

	protected function getDiskService(): DiskMcpService
	{
		if ($this->diskService === null)
		{
			$this->diskService = new DiskMcpService();
		}

		return $this->diskService;
	}

	protected function intArg(array $args, string $name, ?int $default = null): ?int
	{
		if (!array_key_exists($name, $args) || $args[$name] === null || $args[$name] === '')
		{
			return $default;
		}

		return (int)$args[$name];
	}

	protected function stringArg(array $args, string $name, ?string $default = null): ?string
	{
		if (!array_key_exists($name, $args) || $args[$name] === null)
		{
			return $default;
		}

		$value = trim((string)$args[$name]);

		return $value === '' ? $default : $value;
	}

	protected function boolArg(array $args, string $name, bool $default = false): bool
	{
		if (!array_key_exists($name, $args))
		{
			return $default;
		}

		return (bool)$args[$name];
	}

	protected function limitArg(array $args): int
	{
		return max(1, min((int)($args['limit'] ?? 20), 50));
	}

	protected function offsetArg(array $args): int
	{
		return max(0, (int)($args['offset'] ?? 0));
	}

	private function captureCurrentUserContext(): array
	{
		$session = Application::getInstance()->getKernelSession();
		$sessionContext = [];

		foreach (self::USER_SESSION_KEYS as $key)
		{
			$sessionContext[$key] = [
				'exists' => $session->has($key),
				'value' => $session[$key] ?? null,
			];
		}

		return [
			'user' => [
				'exists' => array_key_exists('USER', $GLOBALS),
				'value' => $GLOBALS['USER'] ?? null,
			],
			'session' => $sessionContext,
		];
	}

	private function restoreCurrentUserContext(array $context): void
	{
		$session = Application::getInstance()->getKernelSession();
		foreach ($context['session'] as $key => $state)
		{
			if ($state['exists'])
			{
				$session[$key] = $state['value'];
			}
			else
			{
				unset($session[$key]);
			}
		}

		if ($context['user']['exists'])
		{
			$GLOBALS['USER'] = $context['user']['value'];
		}
		else
		{
			unset($GLOBALS['USER']);
		}
	}
}
