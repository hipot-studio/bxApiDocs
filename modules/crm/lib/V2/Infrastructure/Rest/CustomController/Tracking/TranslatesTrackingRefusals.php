<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Tracking\TraceTooManyBindingsException as RestTraceTooManyBindingsException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceChannelInvalidException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceTooManyBindingsException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceInvalidException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Closure;

/**
 * The one translation of the refusals of the attribution domain into the answers of REST V3, shared by
 * every action of `crm.tracking.trace.*`: a refusal has one meaning wherever it was raised.
 *
 * A missing trace and a trace the user may not read look the same: both are "not found", so the answer
 * does not tell that a hidden trace exists.
 */
trait TranslatesTrackingRefusals
{
	/**
	 * @template TResult
	 * @param int $id the trace or the item the "not found" answer names; 0 when there is none yet
	 * @param Closure(): TResult $operation
	 * @return TResult
	 */
	private function runTracking(int $id, Closure $operation): mixed
	{
		try
		{
			return $operation();
		}
		catch (TraceNotFoundException)
		{
			throw new EntityNotFoundException($id);
		}
		catch (TraceAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (TraceTooManyBindingsException $exception)
		{
			throw new RestTraceTooManyBindingsException($exception->maxBindings);
		}
		catch (TrackingSourceInvalidException $exception)
		{
			throw new RequestValidationException([
				new Error(
					new LocalizableMessage('CRM_V2_REST_TRACKING_SOURCE_INVALID', ['#ID#' => $exception->sourceId]),
					'fields.sourceId',
				),
			]);
		}
		catch (TraceChannelInvalidException $exception)
		{
			throw new RequestValidationException([
				new Error(
					new LocalizableMessage(
						'CRM_V2_REST_TRACKING_CHANNEL_INVALID',
						['#CODE#' => $exception->channelCode],
					),
					'fields.channels',
				),
			]);
		}
	}
}
