<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application;

use Bitrix\Main;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppCollection;
use Bitrix\Rest\Public\Contract\Application\OAuthToken;
use Bitrix\Rest\Public\Provider\Params\ApplicationSelect;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Dto\DtoCollection;

class AppDtoMapper
{
	public function toDto(App $app, ?Request $request = null, bool $includeSecret = false): AppDto
	{
		$dto = new AppDto();

		if ($this->shouldRenderField('id', $request))
		{
			$dto->id = (int)($app->getId());
		}

		if ($this->shouldRenderField('clientId', $request))
		{
			$dto->clientId = $app->getClientId();
		}

		if ($this->shouldRenderSensitiveField('clientSecret', $request, $includeSecret))
		{
			$dto->clientSecret = $app->getClientSecret();
		}

		if ($this->shouldRenderSensitiveField('applicationToken', $request, $includeSecret))
		{
			$dto->applicationToken = $app->getApplicationToken();
		}

		if ($this->shouldRenderField('scopes', $request))
		{
			$dto->scopes = $app->getScope();
		}

		if ($this->shouldRenderField('title', $request))
		{
			$dto->title = $app->getAppName();
		}

		if ($this->shouldRenderField('url', $request))
		{
			$dto->url = $app->getUrl();
		}

		if ($this->shouldRenderField('urlInstall', $request))
		{
			$dto->urlInstall = $app->getUrlInstall();
		}

		if ($this->shouldRenderField('urlSettings', $request))
		{
			$dto->urlSettings = $app->getUrlSettings();
		}

		if ($this->shouldRenderField('mobile', $request))
		{
			$dto->mobile = $app->isMobile();
		}

		if ($this->shouldRenderField('version', $request))
		{
			$dto->version = $app->getVersion();
		}

		if ($this->shouldRenderField('active', $request))
		{
			$dto->active = $app->isActive();
		}

		if ($this->shouldRenderField('installed', $request))
		{
			$dto->installed = $app->isInstalled();
		}

		if ($this->shouldRenderField('dateCreate', $request))
		{
			$dto->dateCreate = $app->getDateCreate()?->toString();
		}

		if ($this->shouldRenderField('dateInstall', $request))
		{
			$dto->dateInstall = $app->getDateInstall()?->toString();
		}

		if ($this->shouldRenderAttributes($request))
		{
			$dto->attributes = $this->mapExternalAttributes($app)->toArray();
		}

		return $dto;
	}

	public function toOAuthTokenDto(OAuthToken $oauthToken): OAuthTokenDto
	{
		$dto = new OAuthTokenDto();
		$dto->accessToken = $oauthToken->getAccessToken();
		$dto->refreshToken = $oauthToken->getRefreshToken();
		$dto->expiresIn = $oauthToken->getExpiresIn();
		$dto->serverEndpoint = $oauthToken->getServerEndpoint();

		return $dto;
	}

	private function shouldRenderAttributes(?Request $request): bool
	{
		return $this->shouldRenderField(ApplicationSelect::FIELD_ATTRIBUTES, $request);
	}

	private function shouldRenderSensitiveField(
		string $field,
		?Request $request,
		bool $includeSecret,
	): bool
	{
		return $includeSecret && $this->shouldRenderField($field, $request);
	}

	private function shouldRenderField(string $field, ?Request $request): bool
	{
		$select = $this->getSelectedFields($request);
		if (!empty($select))
		{
			return in_array($field, $select, true);
		}

		if ($field === ApplicationSelect::FIELD_ATTRIBUTES && $request instanceof ListRequest)
		{
			return false;
		}

		return true;
	}

	private function getSelectedFields(?Request $request): array
	{
		return $request?->select?->getList() ?? [];
	}

	public function nullDto(): AppDto
	{
		return new AppDto();
	}

	public function fromDto(AppDto $dto): App
	{
		throw new Main\NotImplementedException('Method fromDto is not implemented yet');
	}

	public function toDtoCollection(AppCollection $appCollection, ?Request $request): DtoCollection
	{
		$collection = new DtoCollection(AppDto::class);
		foreach ($appCollection as $app)
		{
			$dto = $this->toDto($app, $request);
			$collection->add($dto);
		}

		return $collection;
	}

	private function mapExternalAttributes(App $app): DtoCollection
	{
		$collection = new DtoCollection(AppAttributeDto::class);
		foreach ($app->getExternalAttributes() as $attribute)
		{
			$dto = new AppAttributeDto();
			$collection->add($dto);
			$dto->code = (string)($attribute->getCode());
			$dto->value = $attribute->getValue();
		}
		return $collection;
	}
}
