<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * An operation over an activity, declared as the ordered steps it consists of, and the engine running them.
 *
 * THE ORDER IS THE DECLARATION AND NOTHING ELSE. The steps run in the order they were given, which is the order
 * of legacy; the engine neither reorders them nor holds a rule of its own about any of them. That is what makes
 * the declaration comparable with the owner table of the operation, and the invariants of the write contract -
 * serializing before the event of an add, compressing before the event of an update, taking the snapshot of the
 * bindings before the write, reading the previous counter before recomputing it - hold because the declaration
 * puts the steps in that order and the engine keeps it.
 *
 * THERE IS NO TRANSACTION AROUND ANY OF THIS, on purpose: the table of activities is a hot one, and legacy wraps
 * nothing either. Whatever transactions the domain has stay inside the steps that already have them. The direct
 * consequence is the behaviour on a failure below: a failing step that legacy does not interrupt on leaves the
 * effects above it in place, is logged, and the operation goes on. Reproducing legacy means reproducing that.
 *
 * WHAT ENDS A RUN EARLY. A failure of a step that declares itself aborting, with the errors of that step; or a
 * step that succeeded and asked to stop ({@see PipelineStepInterface::STOP_WITH_SUCCESS}), which ends the run
 * successfully and skips everything below it. A throwable out of a step is treated as its failure: it is logged
 * with the marker of the path and then the same rule applies - only an aborting step ends the operation. The
 * condition of a step is covered by that as well: it reads the provider and everything the steps above wrote, so
 * a throwable out of it is a failure of the same step and is answered for exactly like one out of its effect.
 *
 * A STEP MAY DECLARE ITS THROWABLE TO BE THE CALLER'S ({@see ThrowableTransparentStepInterface}), and then the run
 * neither swallows it nor turns it into a refusal: it is logged like any other and handed on, which is what legacy
 * does with an exception of a subscriber of its events - legacy catches nothing. The steps publishing those events
 * are the ones declaring it; for every other step the rule above stands, and that is a registered deviation from
 * legacy rather than an accident of the engine.
 *
 * @internal
 */
final class Pipeline
{
	/** The key the activity of the operation is returned under, in whatever state the run left it. */
	public const RESULT_ACTIVITY = 'activity';

	/**
	 * The key the state of the whole run is returned under, for a caller that has to answer for more than the
	 * activity: the reverse projection of the legacy facade, which owes its caller the columns the typed model does
	 * not hold, the serialized fields as arrays, the moment the counter turns light at and the keys the calendar
	 * block consumed (section 11 of LBS-1).
	 *
	 * IT IS A SECOND ANSWER AND NOT A WIDER ONE. The activity above stays the answer every other reader takes, and
	 * this one is read by exactly one boundary, so nothing has to know about it to keep working. What is handed over
	 * is the state of the run itself rather than a snapshot of the parts of it somebody might want: which parts the
	 * legacy contract needs is knowledge of that boundary, not of the engine. A reader of it asks questions and
	 * changes nothing - the run is over by then, and a value written into it would reach nobody.
	 *
	 * A REFUSED RUN ANSWERS WITH ITS STATE TOO. Nothing of the reverse projection is read off it - an operation that
	 * stored nothing writes nothing back into the array of its caller - but how far the run got is a question the
	 * same boundary has to answer: the accumulator of errors of the legacy facade is emptied by the check of the
	 * fields and by nothing else ({@see PipelineContext::RUN_VALUE_FIELDS_CHECKED}), so a refusal above that check
	 * and a refusal below it leave the caller with different messages.
	 */
	public const RESULT_CONTEXT = 'context';

	/** The code of the error an aborting step that threw produces: the throwable itself goes to the log. */
	public const ERROR_STEP_FAILED = 'CRM_ACTIVITY_PIPELINE_STEP_FAILED';

	private readonly string $name;

	/** @var PipelineStepInterface[] */
	private readonly array $steps;

	private readonly StepFailureLogger $failureLogger;

	/**
	 * @param string $name the name of the operation, as the lifecycle service of activities names it. It is the
	 *        only thing telling apart the log records of a step shared by two operations.
	 * @param PipelineStepInterface[] $steps in the order of legacy.
	 * @throws ArgumentException on an unnamed pipeline or on anything among the steps that is not a step.
	 */
	public function __construct(string $name, array $steps, ?StepFailureLogger $failureLogger = null)
	{
		if ($name === '')
		{
			throw new ArgumentException('A pipeline of an activity has to be named', 'name');
		}

		foreach ($steps as $step)
		{
			if (!$step instanceof PipelineStepInterface)
			{
				throw new ArgumentException(
					'A pipeline of an activity is declared out of steps, got ' . get_debug_type($step),
					'steps',
				);
			}
		}

		$this->name = $name;
		$this->steps = array_values($steps);
		$this->failureLogger = $failureLogger ?? new StepFailureLogger();
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * The names of the steps in the order they are declared - which is what the tests comparing a pipeline with
	 * its owner table read.
	 *
	 * @return string[]
	 */
	public function getStepNames(): array
	{
		return array_map(static fn(PipelineStepInterface $step): string => $step->getName(), $this->steps);
	}

	/**
	 * Runs the applicable steps in order over the given context and answers with the activity the run left in it.
	 *
	 * @see self::RESULT_ACTIVITY for the successful outcome.
	 */
	public function run(PipelineContext $context): Result
	{
		foreach ($this->steps as $step)
		{
			try
			{
				if (!$step->isApplicable($context))
				{
					continue;
				}

				$stepResult = $step->execute($context);
			}
			catch (Throwable $error)
			{
				$this->failureLogger->logThrowable($this->name, $step->getName(), $context, $error);

				if ($step instanceof ThrowableTransparentStepInterface)
				{
					throw $error;
				}

				if ($step->isAbortingOnFailure())
				{
					return $this->abortedBy($step, $context);
				}

				continue;
			}

			if (self::stopsWithSuccess($stepResult))
			{
				return $this->succeededWith($context);
			}

			if (!$stepResult->isSuccess())
			{
				$this->failureLogger->logErrors($this->name, $step->getName(), $context, $stepResult->getErrors());

				if ($step->isAbortingOnFailure())
				{
					return self::refusedWith($stepResult->getErrors(), $context);
				}
			}
		}

		return $this->succeededWith($context);
	}

	private static function stopsWithSuccess(Result $stepResult): bool
	{
		if (!$stepResult->isSuccess())
		{
			return false;
		}

		return ($stepResult->getData()[PipelineStepInterface::STOP_WITH_SUCCESS] ?? null) === true;
	}

	private function succeededWith(PipelineContext $context): Result
	{
		return (new Result())->setData([
			self::RESULT_ACTIVITY => $context->getActivity(),
			self::RESULT_CONTEXT => $context,
		]);
	}

	private function abortedBy(PipelineStepInterface $step, PipelineContext $context): Result
	{
		return self::refusedWith(
			[
				new Error(
					'The ' . $this->name . ' of an activity was aborted by its step ' . $step->getName(),
					self::ERROR_STEP_FAILED,
					['stepName' => $step->getName()],
				),
			],
			$context,
		);
	}

	/**
	 * The answer of a run an aborting step ended: the errors of that step and the state the run stopped in - see
	 * {@see self::RESULT_CONTEXT} on what the boundary of the legacy facade reads off a refusal.
	 *
	 * @param Error[] $errors
	 */
	private static function refusedWith(array $errors, PipelineContext $context): Result
	{
		return (new Result())
			->addErrors($errors)
			->setData([self::RESULT_CONTEXT => $context])
		;
	}
}
