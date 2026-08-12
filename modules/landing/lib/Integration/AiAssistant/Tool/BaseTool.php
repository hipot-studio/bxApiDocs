<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Log;
use Bitrix\Landing\Rights;
use Bitrix\Main\Application;
use Bitrix\Main\Authentication\Context as UserAuthenticationContext;
use Bitrix\Main\Loader;
use Bitrix\Main\Validation\ValidationService;
use InvalidArgumentException;

if (!class_exists(ToolContract::class, false) || Application::hasInstance())
{
	Loader::requireModule('aiassistant');
}

abstract class BaseTool extends ToolContract
{
	public const ACTION_NAME = '';

	private const USER_SESSION_KEYS = [
		'SESS_AUTH',
		'SESS_OPERATIONS',
	];

	public function __construct(
		private readonly ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($tracedLogger);
	}

	public function getName(): string
	{
		return static::ACTION_NAME;
	}

	public function getInputSchema(): array
	{
		return $this->getInputSchemaDefinition();
	}

	public function run(int $userId, ...$args): array
	{
		$effectiveUserId = $this->resolveEffectiveUserId($userId);
		if ($this->isToolCallLogEnabled())
		{
			$this->logToolCall($userId, $effectiveUserId, $args);
		}

		return parent::run($effectiveUserId, ...$args);
	}

	public function canList(int $userId): bool
	{
		return $userId > 0;
	}

	public function canRun(int $userId): bool
	{
		return $userId > 0;
	}

	protected function validate(object $dto): void
	{
		$validationResult = $this->validationService->validate($dto);

		if (!$validationResult->isSuccess())
		{
			$error = $validationResult->getError();

			throw new InvalidArgumentException("{$error->getCode()}: {$error->getMessage()}");
		}
	}

	protected function resolveEffectiveUserId(int $userId): int
	{
		if ($userId > 0)
		{
			return $userId;
		}

		throw new InvalidArgumentException('User id must be greater than zero.');
	}

	abstract protected function getInputSchemaDefinition(): array;

	protected function assertPermission(bool $isAllowed, string $message): void
	{
		if (!$isAllowed)
		{
			throw new InvalidArgumentException($message);
		}
	}

	protected function isToolCallLogEnabled(): bool
	{
		return Log::isEnabled();
	}

	protected function logToolCall(int $userId, int $effectiveUserId, array $args): void
	{
		(new Log())->addToolCall(
			static::ACTION_NAME,
			static::class,
			$userId,
			$effectiveUserId,
			$args,
		);
	}

	/**
	 * Executes a callback under the explicit landing rights context of the tool caller.
	 */
	protected function runInUserContext(int $userId, callable $callback): mixed
	{
		$userId = $this->resolveEffectiveUserId($userId);
		$previousContext = $this->captureCurrentUserContext();

		try
		{
			$this->setToolUserContext($userId);

			return $callback();
		}
		finally
		{
			$this->restoreUserContext($previousContext);
		}
	}

	private function captureCurrentUserContext(): array
	{
		return [
			'rightsUserId' => Rights::getContextUserId(),
			'user' => $GLOBALS['USER'] ?? null,
			'session' => $this->captureUserSession(),
		];
	}

	private function setToolUserContext(int $userId): void
	{
		$user = new \CUser();
		$authContext = (new UserAuthenticationContext())->setUserId($userId);
		if ($user->UpdateSessionData($authContext) === false)
		{
			throw new InvalidArgumentException('User id must reference an active user.');
		}

		$GLOBALS['USER'] = $user;
		Rights::setContextUserId($userId);
	}

	private function captureUserSession(): array
	{
		$session = Application::getInstance()->getKernelSession();
		$snapshot = [];

		foreach (self::USER_SESSION_KEYS as $key)
		{
			$snapshot[$key] = [
				'exists' => isset($session[$key]),
				'value' => $session[$key] ?? null,
			];
		}

		return $snapshot;
	}

	private function restoreUserContext(array $context): void
	{
		$this->restoreRightsContext($context['rightsUserId']);
		$this->restoreUserSession($context['session']);
		$this->restoreGlobalUser($context['user']);
	}

	private function restoreRightsContext(int $userId): void
	{
		if ($userId > 0)
		{
			Rights::setContextUserId($userId);
		}
		else
		{
			Rights::clearContextUserId();
		}
	}

	private function restoreUserSession(array $sessionSnapshot): void
	{
		$session = Application::getInstance()->getKernelSession();

		foreach ($sessionSnapshot as $key => $state)
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
	}

	private function restoreGlobalUser(mixed $user): void
	{
		if ($user instanceof \CUser)
		{
			$GLOBALS['USER'] = $user;
		}
		else
		{
			unset($GLOBALS['USER']);
		}
	}
}
