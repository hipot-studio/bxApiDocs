<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Writes down a step of a pipeline that failed.
 *
 * THE MARKER OF THE PATH IS THE POINT OF THIS CLASS. While the delegation of the legacy write contract is
 * switched on, the same operation reaches the same steps through two paths - the legacy facade and a native call
 * - and a record that does not say which one it came from is of no use in production: a divergence between the
 * paths becomes indistinguishable from a failure common to both. The marker is derived from the context, never
 * passed in beside it, so a caller cannot get it wrong.
 *
 * WHAT IS NOT LOGGED HERE. The silent skips of legacy: a step whose condition does not hold performs nothing and
 * that is normal behaviour, not a failure. Only a step that failed or threw gets a record, and the consistency
 * probe of the observability phase is what watches the rest.
 *
 * TWO OUTCOMES AND TWO LEVELS OF THEM. A step that threw is a failure of the V2 path - nobody expected it, and
 * the channel is read as "were there errors of the path at all", the signal the stabilization gate rolls the flag
 * back on. A step that returned errors of the domain is the operation refused: incorrect input of a caller, a
 * provider saying no, a broken invariant of the fields - an expected outcome legacy does not log at all, which
 * at the level of an error would fill the same channel with ordinary user input and turn the signal false. So a
 * refusal arrives as a warning, below the threshold the channel keeps by default ('activity_logger_level' in the
 * settings of the module), and {@see self::OUTCOME_FAILED} beside {@see self::OUTCOME_REJECTED} tells the two
 * apart for a reader who lowered that threshold to see both.
 *
 * @internal
 */
final class StepFailureLogger
{
	/** The operation came through the legacy facade. */
	public const PATH_FACADE = 'v2-facade';

	/** The operation came from anywhere else - a direct call of the service, a public command later on. */
	public const PATH_NATIVE = 'v2-native';

	/** The step threw: an unexpected failure of the V2 path, which is what this channel is read for. */
	public const OUTCOME_FAILED = 'failed';

	/** The step returned errors of the domain: the operation was refused, which is an expected outcome. */
	public const OUTCOME_REJECTED = 'rejected';

	private const MESSAGE_FAILED =
		'The step {step} of the {operation} of an activity failed on the {path} path: {reason}';

	private const MESSAGE_REJECTED =
		'The step {step} of the {operation} of an activity refused the operation on the {path} path: {reason}';

	/**
	 * The log channel of the activities, declared in the settings of the module beside the other named channels.
	 * It is a channel of its own and not the general one of the module: the records of the write path are read
	 * against each other - one path against the other, one step against the rest - and a channel shared with
	 * everything else the module logs makes that reading a search instead of a filter.
	 */
	private const LOGGER_ID = 'Activity';

	private ?LoggerInterface $logger;

	/**
	 * @param LoggerInterface|null $logger null takes the channel of the activities, which is where support looks
	 *        for the records of the write path.
	 */
	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger;
	}

	/**
	 * A step that threw. The class of the throwable is part of the reason: its message alone hides what happened.
	 */
	public function logThrowable(
		string $operation,
		string $stepName,
		PipelineContext $context,
		Throwable $error,
	): void
	{
		$this->log(
			$operation,
			$stepName,
			$context,
			$error::class . ': ' . $error->getMessage(),
			level: LogLevel::ERROR,
			outcome: self::OUTCOME_FAILED,
			message: self::MESSAGE_FAILED,
		);
	}

	/**
	 * A step that returned errors: the refusal of the operation, not a failure of the path.
	 *
	 * @param Error[] $errors
	 */
	public function logErrors(
		string $operation,
		string $stepName,
		PipelineContext $context,
		array $errors,
	): void
	{
		$this->log(
			$operation,
			$stepName,
			$context,
			self::describeErrors($errors),
			level: LogLevel::WARNING,
			outcome: self::OUTCOME_REJECTED,
			message: self::MESSAGE_REJECTED,
		);
	}

	private function log(
		string $operation,
		string $stepName,
		PipelineContext $context,
		string $reason,
		string $level,
		string $outcome,
		string $message,
	): void
	{
		$this->getLogger()->log(
			$level,
			$message,
			[
				'step' => $stepName,
				'operation' => $operation,
				'path' => $context->isNativeEntry() ? self::PATH_NATIVE : self::PATH_FACADE,
				'outcome' => $outcome,
				'activityId' => $context->getActivityId(),
				'providerId' => $context->getProvider()?->getId() ?? '',
				'reason' => $reason,
			],
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function describeErrors(array $errors): string
	{
		$descriptions = [];
		foreach ($errors as $error)
		{
			$code = (string)$error->getCode();
			$descriptions[] = $code === '' ? $error->getMessage() : $code . ': ' . $error->getMessage();
		}

		return implode('; ', $descriptions);
	}

	private function getLogger(): LoggerInterface
	{
		$this->logger ??= Container::getInstance()->getLogger(self::LOGGER_ID);

		return $this->logger;
	}
}
