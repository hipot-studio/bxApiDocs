<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Feature\ChatAvatarFeature;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatAvatarPublisher;

class ChatAvatarFeatureInitializer
{
	private const MODULE_ID = 'tasks';
	private const FEATURE_OPTION = 'chat_avatar_feature';
	private const CURRENT_REVISION = 1;
	private const MIGRATION_SCHEDULING_ERROR = 'TASK_CHAT_AVATAR_MIGRATION_SCHEDULING_FAILED';
	private const ACTIVATION_ATTEMPTS_OPTION = 'chat_avatar_activation_attempts';
	private const MAX_ACTIVATION_ATTEMPTS = 5;

	private ChatAvatarPublisher $publisher;

	public function __construct(?ChatAvatarPublisher $publisher = null)
	{
		$this->publisher = $publisher ?? new ChatAvatarPublisher();
	}

	public function initialize(): Result
	{
		if (!$this->isFeatureEnabled())
		{
			return new Result();
		}

		$result = $this->publisher->publish(self::CURRENT_REVISION, $this->getAvatarPaths());
		if (!$result->isSuccess())
		{
			return $result;
		}

		try
		{
			$this->scheduleMigration();
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error(
				$exception->getMessage() ?: 'Could not schedule task chat avatar migration.',
				self::MIGRATION_SCHEDULING_ERROR,
			));
		}

		return $result;
	}

	/**
	 * One-shot agent set by the release update. A failed run keeps the agent for another try at
	 * the interval the update set; the tries are counted in an option and limited, so a portal
	 * the activation cannot heal does not carry the agent forever.
	 */
	public static function activateForReleaseAgent(): string
	{
		try
		{
			(new self())->activateForRelease();
		}
		catch (\Throwable $exception)
		{
			Container::getInstance()->getLogger()->logError($exception);

			return self::retryActivationAgent();
		}

		self::forgetActivationAttempts();

		return '';
	}

	private static function retryActivationAgent(): string
	{
		if (!Feature::isEnabled(ChatAvatarFeature::class))
		{
			self::forgetActivationAttempts();

			return '';
		}

		$attempt = (int)Option::get(self::MODULE_ID, self::ACTIVATION_ATTEMPTS_OPTION, '0') + 1;
		if ($attempt >= self::MAX_ACTIVATION_ATTEMPTS)
		{
			Container::getInstance()->getLogger()->logError(
				'Task chat avatar activation gave up after ' . $attempt . ' attempts.',
			);
			self::forgetActivationAttempts();

			return '';
		}

		Option::set(self::MODULE_ID, self::ACTIVATION_ATTEMPTS_OPTION, (string)$attempt);

		return self::class . '::activateForReleaseAgent();';
	}

	private static function forgetActivationAttempts(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::ACTIVATION_ATTEMPTS_OPTION]);
	}

	public function activateForRelease(): void
	{
		$explicitFeatureValue = $this->getExplicitFeatureValue();
		if ($explicitFeatureValue === false)
		{
			return;
		}

		if ($explicitFeatureValue === null)
		{
			$legacyValue = $this->getFeatureOptionValue();
			if ($legacyValue === 'N' || ($legacyValue !== null && $legacyValue !== 'Y'))
			{
				return;
			}

			if ($legacyValue === null)
			{
				$this->setFeatureOptionValue('Y');
				$this->clearFeatureCache();
			}
		}

		if (!$this->isFeatureEnabled())
		{
			if ($this->getExplicitFeatureValue() === false)
			{
				return;
			}

			throw new SystemException('Task chat avatars are disabled by a feature rule.');
		}

		$result = $this->initialize();
		if ($result->isSuccess())
		{
			if (!$this->isFeatureEnabled())
			{
				throw new SystemException('Task chat avatars are disabled by a feature rule.');
			}

			return;
		}

		if (
			self::isSchedulingFailure($result)
			&& $this->reconcileMigrationSchedulingFailure(static function (): void {})
		)
		{
			return;
		}

		throw new SystemException(
			implode('; ', $result->getErrorMessages()) ?: 'Could not initialize task chat avatars.',
		);
	}

	public static function isSchedulingFailure(Result $result): bool
	{
		return $result->getErrorCollection()->getErrorByCode(self::MIGRATION_SCHEDULING_ERROR) !== null;
	}

	public function onFeatureDisabled(): void
	{
		$this->markMigrationForRepair();
	}

	public function onAfterSetOption(Event $event): void
	{
	}

	protected function isFeatureEnabled(): bool
	{
		return Feature::isEnabled(ChatAvatarFeature::class);
	}

	protected function getExplicitFeatureValue(): ?bool
	{
		return ChatAvatarFeature::getExplicitValue();
	}

	protected function getFeatureOptionValue(): ?string
	{
		return Option::getRealValue(self::MODULE_ID, self::FEATURE_OPTION, '');
	}

	protected function setFeatureOptionValue(string $value): void
	{
		Option::set(self::MODULE_ID, self::FEATURE_OPTION, $value, '');
	}

	protected function clearFeatureCache(): void
	{
		Feature::clearCache();
	}

	protected function reconcileMigrationSchedulingFailure(callable $rollback): bool
	{
		return UpdateTaskChatAvatarStepper::reconcileSchedulingFailure($rollback);
	}

	protected function scheduleMigration(): void
	{
		UpdateTaskChatAvatarStepper::scheduleMigration();
	}

	protected function markMigrationForRepair(): void
	{
		UpdateTaskChatAvatarStepper::markMigrationForRepair();
	}

	private function getAvatarPaths(): array
	{
		$avatarDirectory = Application::getDocumentRoot() . '/bitrix/images/tasks/chat_avatar';

		return [
			'default' => $avatarDirectory . '/default.png',
			'deferred' => $avatarDirectory . '/deferred.png',
			'expired' => $avatarDirectory . '/expired.png',
			'completed' => $avatarDirectory . '/completed.png',
		];
	}
}
