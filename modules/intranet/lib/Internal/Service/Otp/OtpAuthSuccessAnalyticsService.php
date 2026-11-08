<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Authentication;
use Bitrix\Main\Context;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Security\Mfa\Otp;
use Bitrix\Security\Mfa\RecoveryCodesTable;
use Closure;
use Throwable;

final class OtpAuthSuccessAnalyticsService
{
	private const EVENT = 'fa_auth_success';
	private const TOOL = 'security';
	private const CATEGORY = 'fa_auth_form';
	private const OTP_TYPE = 'OTP';
	private const TYPE_FIELD = 'TYPE';
	private const CODE_FIELD = 'USER_OTP';
	private const METHOD_FIELD = 'OTP_AUTHENTICATION_METHOD';

	private readonly Closure $isOtpActive;
	private readonly Closure $analyticsSender;
	private array $sentRequests = [];

	public function __construct(
		?callable $isOtpActive = null,
		?callable $analyticsSender = null,
	)
	{
		$this->isOtpActive = Closure::fromCallable(
			$isOtpActive ?? static fn(int $userId): bool => (
				Loader::includeModule('security')
					&& Otp::isOtpEnabled()
					&& Otp::getByUser($userId)->isActivated()
			),
		);
		$this->analyticsSender = Closure::fromCallable(
			$analyticsSender ?? static function (int $userId, string $method): void {
				$event = new AnalyticsEvent(self::EVENT, self::TOOL, self::CATEGORY);
				$event
					->setUserId($userId)
					->setP1($method)
					->setP5((string)$userId)
					->send()
				;
			},
		);
	}

	public function send(array $eventData): void
	{
		$userId = $this->extractUserId($eventData['user_fields']['ID'] ?? null);
		if ($userId <= 0)
		{
			return;
		}

		$context = $eventData['context'] ?? null;
		if (!$context instanceof Authentication\Context || !$context->isOtpUsed())
		{
			return;
		}

		$request = Context::getCurrent()?->getRequest();
		if (!$request instanceof HttpRequest || !$request->isPost())
		{
			return;
		}

		try
		{
			$isOtpActive = $this->isOtpActive;
			if (!$isOtpActive($userId))
			{
				return;
			}

			$method = $this->getSubmittedAuthenticationMethod($request);
		}
		catch (Throwable)
		{
			return;
		}

		if ($method === null || $this->isAlreadySent($userId, $context, $request))
		{
			return;
		}

		try
		{
			$analyticsSender = $this->analyticsSender;
			$analyticsSender($userId, $method);
		}
		catch (Throwable)
		{
			// Analytics failures must not block successful authorization.
		}
	}

	private function extractUserId(mixed $userId): int
	{
		if (is_int($userId))
		{
			return $userId > 0 ? $userId : 0;
		}

		if (!is_string($userId) || preg_match('/^[1-9][0-9]*$/D', $userId) !== 1)
		{
			return 0;
		}

		return (int)$userId;
	}

	private function getSubmittedAuthenticationMethod(HttpRequest $request): ?string
	{
		if ($request->getPost(self::TYPE_FIELD) !== self::OTP_TYPE)
		{
			return null;
		}

		$code = $request->getPost(self::CODE_FIELD);
		if (!is_string($code) || trim($code) === '')
		{
			return null;
		}

		$method = $request->getPost(self::METHOD_FIELD);
		if (!is_string($method))
		{
			return null;
		}
		if (
			$method === AuthenticationMethod::OTP
			&& preg_match(RecoveryCodesTable::CODE_PATTERN, $code) === 1
		)
		{
			$method = AuthenticationMethod::RECOVERY_CODES;
		}

		return AuthenticationMethod::getAnalyticsValue($method);
	}

	private function isAlreadySent(
		int $userId,
		Authentication\Context $context,
		HttpRequest $request,
	): bool
	{
		$key = $userId . ':' . $this->getRequestKey($context, $request);
		if (isset($this->sentRequests[$key]))
		{
			return true;
		}

		$this->sentRequests[$key] = true;

		return false;
	}

	private function getRequestKey(Authentication\Context $context, HttpRequest $request): string
	{
		$requestId = $context->getRequestId();
		if (is_string($requestId) && $requestId !== '')
		{
			return $requestId;
		}

		return 'request:' . spl_object_id($request);
	}
}
