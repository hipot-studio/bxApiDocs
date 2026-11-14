<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Tracking\TraceTooManyBindingsException as RestTraceTooManyBindingsException;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Tracking\TrackingSourceCodeNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\ExpensesPackNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceChannelInvalidException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceTooManyBindingsException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceInvalidException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceNotFoundException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceRefusedException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingValidationException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Closure;

/**
 * The one translation of the refusals of the end-to-end analytics into the answers of REST V3, shared by
 * every action of `crm.tracking.*`: a refusal has one meaning wherever it was raised.
 *
 * A missing trace and a trace the user may not read look the same: both are "not found", so the answer
 * does not tell that a hidden trace exists.
 *
 * A refused value of a source or of an expenses pack names its field in the fields of the request, and its
 * phrase is the one of the service that refused it.
 */
trait TranslatesTrackingRefusals
{
	/**
	 * @template TResult
	 * @param int $id the trace or the item the "not found" answer names; 0 when there is none yet. A source
	 * and an expenses pack are named by their own refusals.
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
		catch (TraceAccessDeniedException|TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (TrackingSourceNotFoundException $exception)
		{
			$address = $exception->address;
			if ($address->code !== null)
			{
				throw new TrackingSourceCodeNotFoundException($address->code);
			}

			throw new EntityNotFoundException((int)$address->id);
		}
		catch (ExpensesPackNotFoundException $exception)
		{
			throw new EntityNotFoundException($exception->packId);
		}
		catch (TrackingSourceRefusedException $exception)
		{
			throw new Rest\Exception\Tracking\TrackingSourceRefusedException($exception->reason);
		}
		catch (TrackingValidationException $exception)
		{
			throw new RequestValidationException([
				new Error($exception->getLocalizableMessage(), 'fields.' . $exception->field),
			]);
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
