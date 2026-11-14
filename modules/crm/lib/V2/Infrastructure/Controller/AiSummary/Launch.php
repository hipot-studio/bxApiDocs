<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Controller\AiSummary;

use Bitrix\Crm\V2\Internal\Exception\AiSummary\ElementNotReadableException;
use Bitrix\Crm\V2\Internal\Exception\AiSummary\SummaryUnavailableException;
use Bitrix\Crm\V2\Internal\Service\AiSummary\ClientSummaryLaunchService;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Request;

/**
 * API-01: the point the card of a CRM entity opens the summary of its client from.
 *
 * The refusals are told apart by a stable code and never by the reason of the engine: the client
 * shows one of three states of its own, and the internal reason of a failed launch is not one of
 * them.
 *
 * The rights are asked once per click and not here: the service guards the launch it starts - it is
 * the one an asynchronous job follows, and by the time that job runs there is no user left to ask
 * (ERR-009). The controller reads the answer off the type of the refusal and turns it into the code
 * of the contract, so the same two questions are not asked twice over one click.
 *
 * Neither the measure of the feature nor the rights are worked out anywhere in this scenario: both are
 * asked of the provider that draws the button, so a launch is refused by exactly what refuses it.
 */
final class Launch extends Controller
{
	private const ERROR_SUMMARY_UNAVAILABLE = 'summary_unavailable';
	private const ERROR_ACCESS_DENIED = 'access_denied';
	private const ERROR_INVALID_REQUEST = 'invalid_request';
	private const ERROR_LAUNCH_FAILED = 'launch_failed';

	private ClientSummaryLaunchService $launchService;

	/**
	 * The request comes first because that is the only argument the engine builds a controller with.
	 *
	 * @see \Bitrix\Main\Engine\ControllerBuilder::build
	 */
	public function __construct(
		?Request $request = null,
		?ClientSummaryLaunchService $launchService = null,
	)
	{
		parent::__construct($request);

		$this->launchService = $launchService ?? new ClientSummaryLaunchService();
	}

	/**
	 * @return array{chatId: int}|null identifier of the chat to open, or null with one error of API-01
	 */
	#[Rule\Scope(ActionFilter\Scope::AJAX)]
	#[Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function runAction(int $entityTypeId, int $entityId): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		// the type is told apart from the right on purpose: an unknown type says nothing about any element
		if ($entityTypeId <= 0 || $entityId <= 0 || !$this->launchService->isSupportedEntityType($entityTypeId))
		{
			$this->addError(new Error('The entity is not one a summary is written for.', self::ERROR_INVALID_REQUEST));

			return null;
		}

		try
		{
			return [
				'chatId' => $this->launchService->launch($userId, $entityTypeId, $entityId),
			];
		}
		catch (SummaryUnavailableException)
		{
			$this->addError(new Error('The client summary is not available.', self::ERROR_SUMMARY_UNAVAILABLE));
		}
		catch (ElementNotReadableException)
		{
			$this->addError(new Error('Access denied.', self::ERROR_ACCESS_DENIED));
		}
		catch (\InvalidArgumentException)
		{
			$this->addError(new Error('The entity is not one a summary is written for.', self::ERROR_INVALID_REQUEST));
		}
		catch (\Throwable)
		{
			$this->addError(new Error('The summary was not launched.', self::ERROR_LAUNCH_FAILED));
		}

		return null;
	}

	/**
	 * The identifiers arrive from the client as strings, and reading them is the work of the binder:
	 * it takes a numeric string for the declared int and refuses anything else. A parameter it could
	 * not read is a malformed request of API-01, so the refusal is told by the code of the contract
	 * and never by the message of the engine.
	 */
	protected function runProcessingBinderThrowable(BinderArgumentException $e): void
	{
		$this->addError(new Error('The request of the summary is malformed.', self::ERROR_INVALID_REQUEST));
	}
}
