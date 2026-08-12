<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Main;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter\CheckIncomingJwt;
use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\MarketSubscriptionGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Service\DeveloperKeyService;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\ApiKey;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\ApplicationKey;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;

final class VibecodePort extends Controller implements IncomingServerAware
{
	private NetworkService $networkService;
	private ?string $incomingServerIss = null;
	private MarketSubscriptionGate $subscriptionGate;

	public function init(): void
	{
		parent::init();
		$this->networkService = new NetworkService();
		$this->subscriptionGate = new MarketSubscriptionGate();
	}

	public function setIncomingServerIss(?string $iss): void
	{
		$this->incomingServerIss = $iss;
	}

	public function setSubscriptionGate(MarketSubscriptionGate $gate): void
	{
		$this->subscriptionGate = $gate;
	}

	protected function getDefaultPreFilters()
	{
		return [
			new HttpMethod([HttpMethod::METHOD_POST]),
			new CheckIncomingJwt(),
			new Csrf(false),
		];
	}

	public function configureActions(): array
	{
		return [
			'ping' => [
				'-prefilters' => [
					CheckIncomingJwt::class,
				],
			],
			'getPortalNetworkId' => [
				'-prefilters' => [
					CheckIncomingJwt::class,
				],
			],
		];
	}

	public function getDeveloperKeyAction(int $userId): Response\AjaxJson
	{
		try
		{
			$developerKey = (new DeveloperKeyService(EntryPoint::vibecode($this->incomingServerIss)))->issueFor($userId);

			return Response\AjaxJson::createSuccess(
				['webhookUrl' => $developerKey->url,]
			);
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'WEBHOOK_ISSUE_FAILED'));

			return Response\AjaxJson::createError($this->errorCollection);
		}
	}

	public function getDeveloperKeyByNetworkUserIdAction(string $networkUserId): Response\AjaxJson
	{
		try
		{
			$userId = $this->networkService->getUserIdByNetworkId($networkUserId);
			if ($userId === null)
			{
				$this->addError(new Error(
					'Portal user not found for the given network user id',
					'NETWORK_USER_NOT_FOUND',
				));

				return Response\AjaxJson::createError($this->errorCollection);
			}

			$developerKey = (new DeveloperKeyService(EntryPoint::vibecode($this->incomingServerIss)))->issueFor($userId);

			return Response\AjaxJson::createSuccess(
				['webhookUrl' => $developerKey->url,]
			);
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'WEBHOOK_ISSUE_FAILED'));

			return Response\AjaxJson::createError($this->errorCollection);
		}
	}

	/**
	 * @param string[] $scopes
	 */
	public function createApiKeyAction(int $userId, array $scopes, string $title): Response\AjaxJson
	{
		try
		{
			$webhookUrl = (new ApiKey\Issuer(EntryPoint::vibecode($this->incomingServerIss), subscriptionGate: $this->subscriptionGate))->issue($userId, $scopes, $title);

			return Response\AjaxJson::createSuccess(['webhookUrl' => $webhookUrl]);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addError(new Error($exception->getMessage(), $exception->getErrorCode() ?? 'APIKEY_ISSUE_ACCESS_FAILED'));
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof Main\AccessDeniedException)
			{
				$this->addError(new Error($previousException->getMessage(), 'APIKEY_ISSUE_ACCESS_FAILED'));
			}
			else
			{
				throw new $previousException;
			}
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'APIKEY_ISSUE_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	/**
	 * @param string[] $scopes
	 * @param array<string, string> $menuTitles
	 */
	public function createApplicationKeyAction(
		int $userId,
		string $handlerUrl,
		array $scopes,
		string $title,
		bool $onlyApi = true,
		bool $mobile = false,
		string $installUrl = '',
		array $menuTitles = [],
		?string $applicationToken = null,
	): Response\AjaxJson
	{
		try
		{
			$app = (new ApplicationKey\Issuer(EntryPoint::vibecode($this->incomingServerIss), subscriptionGate: $this->subscriptionGate))->issue(
				$userId,
				$handlerUrl,
				$scopes,
				$title,
				$onlyApi,
				$mobile,
				$installUrl,
				$menuTitles,
				$applicationToken,
			);

			return Response\AjaxJson::createSuccess([
				'clientId' => $app->getClientId(),
				'clientSecret' => $app->getClientSecret(),
				'applicationToken' => $app->getApplicationToken(),
				'appId' => (int)$app->getId(),
			]);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addError(new Error($exception->getMessage(), $exception->getErrorCode() ?? 'APPKEY_INSTALL_ACCESS_FAILED'));
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof Main\AccessDeniedException)
			{
				$this->addError(new Error($previousException->getMessage(), 'APPKEY_INSTALL_ACCESS_FAILED'));
			}
			else
			{
				throw new $previousException;
			}
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'APPKEY_INSTALL_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	/**
	 * Cloud-shared sibling of createApiKey: identifies the portal user by their
	 * Bitrix24.Network global id (used on CLOUD portals where the caller has no
	 * portal-internal user id). Resolves to a local USER_ID, then issues exactly
	 * like createApiKey.
	 *
	 * @param string[] $scopes
	 */
	public function createApiKeyByNetworkUserIdAction(string $networkUserId, array $scopes, string $title): Response\AjaxJson
	{
		try
		{
			$userId = $this->networkService->getUserIdByNetworkId($networkUserId);
			if ($userId === null)
			{
				$this->addError(new Error(
					'Portal user not found for the given network user id',
					'NETWORK_USER_NOT_FOUND',
				));

				return Response\AjaxJson::createError($this->errorCollection);
			}

			$webhookUrl = (new ApiKey\Issuer(EntryPoint::vibecode($this->incomingServerIss), subscriptionGate: $this->subscriptionGate))->issue($userId, $scopes, $title);

			return Response\AjaxJson::createSuccess(['webhookUrl' => $webhookUrl]);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addError(new Error($exception->getMessage(), $exception->getErrorCode() ?? 'APIKEY_ISSUE_ACCESS_FAILED'));
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof Main\AccessDeniedException)
			{
				$this->addError(new Error($previousException->getMessage(), 'APIKEY_ISSUE_ACCESS_FAILED'));
			}
			else
			{
				throw new $previousException;
			}
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'APIKEY_ISSUE_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	/**
	 * Cloud-shared sibling of createApplicationKey (see createApiKeyByNetworkUserId).
	 *
	 * @param string[] $scopes
	 * @param array<string, string> $menuTitles
	 */
	public function createApplicationKeyByNetworkUserIdAction(
		string $networkUserId,
		string $handlerUrl,
		array $scopes,
		string $title,
		bool $onlyApi = true,
		bool $mobile = false,
		string $installUrl = '',
		array $menuTitles = [],
		?string $applicationToken = null,
	): Response\AjaxJson
	{
		try
		{
			$userId = $this->networkService->getUserIdByNetworkId($networkUserId);
			if ($userId === null)
			{
				$this->addError(new Error(
					'Portal user not found for the given network user id',
					'NETWORK_USER_NOT_FOUND',
				));

				return Response\AjaxJson::createError($this->errorCollection);
			}

			$app = (new ApplicationKey\Issuer(EntryPoint::vibecode($this->incomingServerIss), subscriptionGate: $this->subscriptionGate))->issue(
				$userId,
				$handlerUrl,
				$scopes,
				$title,
				$onlyApi,
				$mobile,
				$installUrl,
				$menuTitles,
				$applicationToken,
			);

			return Response\AjaxJson::createSuccess([
				'clientId' => $app->getClientId(),
				'clientSecret' => $app->getClientSecret(),
				'applicationToken' => $app->getApplicationToken(),
				'appId' => (int)$app->getId(),
			]);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addError(new Error($exception->getMessage(), $exception->getErrorCode() ?? 'APPKEY_INSTALL_ACCESS_FAILED'));
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof Main\AccessDeniedException)
			{
				$this->addError(new Error($previousException->getMessage(), 'APPKEY_INSTALL_ACCESS_FAILED'));
			}
			else
			{
				throw new $previousException;
			}
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'APPKEY_INSTALL_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	public function checkConnectionAction(): Response\AjaxJson
	{
		return Response\AjaxJson::createSuccess(
			['OK']
		);
	}

	public function pingAction(): Response\AjaxJson
	{
		return Response\AjaxJson::createSuccess(
			['OK']
		);
	}

	public function getPortalNetworkIdAction(): Response\AjaxJson
	{
		if (!$this->networkService->isCloudPortal())
		{
			$this->addError(new Error(
				'Portal network id is available on cloud portals only',
				'NOT_CLOUD_PORTAL',
			));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		$networkId = $this->networkService->getPortalNetworkId();
		if ($networkId === null)
		{
			$this->addError(new Error(
				'Portal network id is not configured on this cloud portal',
				'NETWORK_ID_UNAVAILABLE',
			));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		return Response\AjaxJson::createSuccess(['networkId' => $networkId]);
	}
}
