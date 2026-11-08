<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Service\Avatar\AvatarImageProcessor;
use Bitrix\Mail\Internal\Service\Sender\Avatar\ExternalSenderResolver;
use Bitrix\Mail\Internal\Service\Sender\Avatar\SenderAvatarChange;
use Bitrix\Mail\Internal\Service\Sender\Avatar\SenderAvatarResourceService;
use Bitrix\Mail\Internal\Service\Sender\Avatar\SenderAvatarService;
use Bitrix\Mail\Internal\Service\Sender\Avatar\SenderAvatarViewModelBuilder;
use Bitrix\Mail\Public\Service\Sender\Avatar\MessageAccessCheckService;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class SenderAvatar extends Base
{
	private const VIEW_REFUSAL_STATUSES = [
		ExternalSenderResolver::ERROR_ACCESS_DENIED => 403,
		ExternalSenderResolver::ERROR_INVALID_SENDER => 404,
		SenderAvatarResourceService::ERROR_NOT_SUPPORTED => 404,
		SenderAvatarService::ERROR_VERSION_CONFLICT => 404,
		AvatarImageProcessor::ERROR_IMAGE_INVALID => 404,
	];

	public function configureActions(): array
	{
		return [
			'get' => [
				'+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
			],
			'save' => [
				'+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
			],
			'view' => [
				'-prefilters' => [Csrf::class],
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_GET]),
					new CloseSession(),
				],
			],
		];
	}

	public function getAction(int $messageId, string $messageAccessToken = ''): ?array
	{
		if (!Feature::isSenderAvatarAvailable())
		{
			$this->addLocalizedErrors(self::notSupported());

			return null;
		}

		$result = self::service()->get($messageId, $messageAccessToken);
		if (!$result->isSuccess())
		{
			$this->addLocalizedErrors($result);

			return null;
		}

		return SenderAvatarViewModelBuilder::build($messageId, $messageAccessToken, $result->getData());
	}

	public function saveAction(int $messageId, array $change, string $messageAccessToken = ''): ?array
	{
		if (!Feature::isSenderAvatarAvailable())
		{
			$this->addLocalizedErrors(self::notSupported());

			return null;
		}

		$changeResult = SenderAvatarChange::fromArray($change);
		if (!$changeResult->isSuccess())
		{
			$this->addLocalizedErrors($changeResult);

			return null;
		}

		$service = self::service();
		$saved = $service->save($messageId, $changeResult->getData()['change'], $messageAccessToken);
		if (!$saved->isSuccess())
		{
			$this->addLocalizedErrors($saved);

			return null;
		}

		$current = $service->get($messageId, $messageAccessToken);
		if (!$current->isSuccess())
		{
			$this->addLocalizedErrors($current);

			return null;
		}

		return SenderAvatarViewModelBuilder::build($messageId, $messageAccessToken, $current->getData());
	}

	public function viewAction(int $messageId, int $revision, string $messageAccessToken = ''): ?HttpResponse
	{
		if (!Feature::isSenderAvatarAvailable())
		{
			return $this->refuseImage(self::notSupported());
		}

		$result = self::service()->getImage($messageId, $revision, $messageAccessToken);
		if (!$result->isSuccess())
		{
			return $this->refuseImage($result);
		}

		$content = (string)$result->getData()['content'];
		$response = new HttpResponse();
		$response->setContent($content);
		$response->getHeaders()->set('Content-Type', 'image/png');
		$response->getHeaders()->set('Content-Disposition', 'inline; filename="avatar.png"');
		$response->getHeaders()->set('Content-Length', (string)strlen($content));
		self::addProtectiveHeaders($response);

		return $response;
	}

	private static function service(): SenderAvatarService
	{
		return new SenderAvatarService(new ExternalSenderResolver(new MessageAccessCheckService()));
	}

	private static function notSupported(): Result
	{
		return (new Result())->addError(new Error(
			SenderAvatarResourceService::ERROR_NOT_SUPPORTED,
			SenderAvatarResourceService::ERROR_NOT_SUPPORTED,
		));
	}

	private function refuseImage(Result $result): null
	{
		$code = $result->getError()?->getCode();
		$response = Context::getCurrent()->getResponse();
		$response->setStatus(is_string($code) ? (self::VIEW_REFUSAL_STATUSES[$code] ?? 500) : 500);
		self::addProtectiveHeaders($response);
		$this->addLocalizedErrors($result);

		return null;
	}

	private static function addProtectiveHeaders(HttpResponse $response): void
	{
		$response->getHeaders()->set('Cache-Control', 'private, no-store');
		$response->getHeaders()->set('X-Content-Type-Options', 'nosniff');
	}

	private function addLocalizedErrors(Result $result): void
	{
		Loc::loadMessages(__FILE__);
		foreach ($result->getErrors() as $error)
		{
			$code = $error->getCode();
			$message = is_string($code) ? Loc::getMessage($code) : null;
			$this->addError(new Error($message ?? $error->getMessage(), $code, $error->getCustomData()));
		}
	}

}
