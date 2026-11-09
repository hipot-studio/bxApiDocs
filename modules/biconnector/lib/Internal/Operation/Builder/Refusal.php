<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\ActAsFailure;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Refusals the builder operations share, worded as the refusals of the MCP tools
 * (BiToolBehaviour) so that a tool answers the same on either path.
 *
 * The structural part of a refusal travels in Result::getData(), never in the custom data of the error.
 */
final class Refusal
{
	public const UNAVAILABLE_DASHBOARD_MESSAGE =
		'Dashboard is temporarily unavailable. Try again in a moment, or pick another report.'
	;
	public const UNAVAILABLE_BI_MESSAGE =
		'The BI request could not be completed right now: the BI service may be busy or still syncing. '
		. 'Try again in a moment.'
	;
	// Longest refusal the API composes is a couple of sentences plus a value list.
	private const MAX_REFUSAL_LENGTH = 500;
	// Integrator\Sender::parseJsonResponse() wraps a body it could not decode into an error of its own.
	private const UNPARSED_RESPONSE_PREFIX = 'Could not parse server response';

	/**
	 * The client's arguments are refused by a helper that describes the refusal in the custom data
	 * of its error; that description moves into the data of the result.
	 */
	public static function invalidArgument(Result $refused): Result
	{
		$error = $refused->getErrors()[0];
		$payload = $error->getCustomData();

		return (new Result())
			->addError(new Error($error->getMessage(), FailureCode::InvalidArgument->value))
			->setData(is_array($payload) ? $payload : [])
		;
	}

	public static function error(FailureCode $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code->value));
	}

	/**
	 * A `400` phrased by the API tells what to change in the request and is passed through;
	 * anything else is masked as a temporarily unavailable dashboard.
	 */
	public static function integratorFailure(IntegratorResponse $response, array $context): Result
	{
		$errors = $response->getErrors();
		$actAsRefusal = self::actAsFailure($errors, $context);
		if ($actAsRefusal !== null)
		{
			return $actAsRefusal;
		}

		$message = trim(($errors[0] ?? null)?->getMessage() ?? '');
		if (
			$response->getStatus() !== IntegratorResponse::STATUS_BAD_REQUEST
			|| !self::isRecognizedRefusal($message)
		)
		{
			return self::unavailableDashboard($errors, $context);
		}

		AiToolsLogger::logWarning($errors, $context);

		return self::error(FailureCode::DomainRule, $message);
	}

	/**
	 * A request Superset could not run as the actor is not transient: the client must not be told to retry.
	 * An outdated box is FeatureDisabled, told by ActAsFailure::OUTDATED_INSTANCE_MESSAGE from BI Builder switched
	 * off (see isOutdatedInstance()); the rights of the tables and act-as itself are AccessDenied.
	 *
	 * @param Error[] $errors
	 * @return Result|null The refusal, or null when the errors say nothing about act-as.
	 */
	public static function actAsFailure(array $errors, array $context): ?Result
	{
		$message = ActAsFailure::findMessage($errors);
		if ($message === null)
		{
			return null;
		}

		AiToolsLogger::logErrors($errors, $context);

		return self::error(
			ActAsFailure::isOutdatedInstance($errors) ? FailureCode::FeatureDisabled : FailureCode::AccessDenied,
			$message,
		);
	}

	/**
	 * Whether a refusal is the one of a box older than reading as the actor requires: the code is FeatureDisabled
	 * as for BI Builder switched off, while the reason and what to do about it differ.
	 */
	public static function isOutdatedInstance(Result $refused): bool
	{
		$error = $refused->getErrors()[0] ?? null;

		return $error !== null
			&& $error->getCode() === FailureCode::FeatureDisabled->value
			&& $error->getMessage() === ActAsFailure::OUTDATED_INSTANCE_MESSAGE
		;
	}

	/**
	 * A failed read of dashboard data (overview, filter values), worded as
	 * BiToolBehaviour::dashboardReadFailureException(): read as the person, a `403`/`404` is a refusal,
	 * not a dashboard to wait for.
	 */
	public static function dashboardReadFailure(IntegratorResponse $response, array $context): Result
	{
		$errors = $response->getErrors();
		if (
			ActAsFailure::findMessage($errors) === null
			&& ActAsFailure::isDashboardRefused($response)
			&& Feature::isTablePermissionsEnabled()
		)
		{
			AiToolsLogger::logErrors($errors, $context);

			return self::error(FailureCode::AccessDenied, ActAsFailure::DASHBOARD_ACCESS_DENIED_MESSAGE);
		}

		return self::integratorFailure($response, $context);
	}

	/**
	 * @param Error[] $errors
	 */
	public static function unavailableDashboard(array $errors, array $context): Result
	{
		AiToolsLogger::logWarning($errors, $context);

		$message = self::UNAVAILABLE_DASHBOARD_MESSAGE;
		if (self::isDebugLogEnabled())
		{
			$details = implode('; ', array_map(static fn(Error $e): string => $e->getMessage(), $errors));
			$message .= ' | bi_debug: ' . $details . ' | ctx: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
		}

		return self::error(FailureCode::Unavailable, $message);
	}

	/**
	 * A failure of BI Builder or of its storage where no dashboard is to blame, as unavailableBiException()
	 * of the tools words it: a refusal of act-as keeps its own answer, see actAsFailure().
	 *
	 * @param Error[] $errors
	 */
	public static function unavailableBi(array $errors, array $context): Result
	{
		$actAsRefusal = self::actAsFailure($errors, $context);
		if ($actAsRefusal !== null)
		{
			return $actAsRefusal;
		}

		AiToolsLogger::logWarning($errors, $context);

		$message = self::UNAVAILABLE_BI_MESSAGE;
		if (self::isDebugLogEnabled())
		{
			$details = implode('; ', array_map(static fn(Error $e): string => $e->getMessage(), $errors));
			$message .= ' | bi_debug: ' . $details . ' | ctx: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
		}

		return self::error(FailureCode::Unavailable, $message);
	}

	public static function appendDebugDetail(string $message, string $detail): string
	{
		if (self::isDebugLogEnabled())
		{
			$message .= ' | bi_debug: ' . $detail;
		}

		return $message;
	}

	private static function isRecognizedRefusal(string $message): bool
	{
		if ($message === '' || mb_strlen($message) > self::MAX_REFUSAL_LENGTH)
		{
			return false;
		}

		if (str_contains($message, '<'))
		{
			return false;
		}

		return !str_starts_with($message, self::UNPARSED_RESPONSE_PREFIX);
	}

	private static function isDebugLogEnabled(): bool
	{
		return Option::get('biconnector', 'bgpt_bi_expand_log', 'N') === 'Y';
	}
}
