<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Settings\SettingDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Settings\SettingMetaDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Settings\SettingNotFoundException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Settings\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Settings\ListRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Settings\SetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Settings\RestSettingsProvider;
use Bitrix\Crm\V2\Internal\Entity\Settings\Validation\SettingValidationError;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingsProvider;
use Bitrix\Crm\V2\Public\Command\Settings\UpdateSettingCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use RuntimeException;

#[DtoType(SettingDto::class)]
final class Settings extends RestController
{
	#[Title('Get CRM setting')]
	#[Description(
		'Get one CRM setting available to the current user by its identifier. '
		. 'Unknown and unreadable identifiers return the same 404 response.',
	)]
	#[McpTool(
		toolSetCode: 'crm_rest_v3_settings',
		descriptionOverride: 'Get a CRM setting by its string id, including its current value and value schema. '
			. 'Only settings readable by the current user are available. '
			. 'Unknown and unreadable ids return the same 404 response.',
	)]
	public function getAction(GetRequest $request): GetResponse
	{
		$result = $this->getProvider()->getById($request->id, $this->getPermissions());
		if ($result->getError()?->getCode() === SettingsProvider::ERROR_NOT_AVAILABLE)
		{
			throw new SettingNotFoundException();
		}

		$this->assertReadSuccess($result);

		return new GetResponse($this->toDto($result->getData()));
	}

	#[Title('List CRM settings')]
	#[Description(
		'List CRM settings readable by the current user. Each result.items[] entry contains an id, current value, '
		. 'and meta.schema with the accepted value format and constraints. Pass its id and a value matching '
		. 'meta.schema to crm.settings.set; write access is checked separately.',
	)]
	#[McpTool(
		toolSetCode: 'crm_rest_v3_settings',
		descriptionOverride: 'List CRM settings readable by the current user. Each setting includes its string id, '
			. 'current value, and meta.schema describing the value accepted by crm.settings.set.',
	)]
	public function listAction(ListRequest $request): ListResponse
	{
		$result = $this->getProvider()->getList($this->getPermissions());
		$this->assertReadSuccess($result);
		$items = new DtoCollection(SettingDto::class);
		foreach ($result->getData()['settings'] as $item)
		{
			$items->add($this->toDto($item));
		}

		return new ListResponse($items);
	}

	#[Title('Set CRM setting')]
	#[Description(
		'Set one CRM setting by its identifier. Use get or list to discover the identifier and value schema. '
		. 'The user must be allowed to change it; if reading after a successful write fails, the write is not rolled back.',
	)]
	#[McpTool(
		toolSetCode: 'crm_rest_v3_settings',
		descriptionOverride: 'Set one CRM setting by its string id and a value matching its meta.schema from get or list. '
			. 'Write access is checked separately. Returns the setting after the write; a failed readback does not '
			. 'roll back a successful write.',
	)]
	public function setAction(SetRequest $request): GetResponse
	{
		$result = (new UpdateSettingCommand(
			$request->id,
			$request->value,
			(int)$this->getCurrentUser()->getId(),
		))->run();
		$this->assertWriteSuccess($result);

		return $this->getAction($this->createGetRequest($request->id));
	}

	private function getProvider(): RestSettingsProvider
	{
		return ServiceLocator::getInstance()->get(RestSettingsProvider::class);
	}

	private function getPermissions(): UserPermissions
	{
		return new UserPermissions((int)$this->getCurrentUser()->getId());
	}

	private function createGetRequest(string $id): GetRequest
	{
		$request = new GetRequest(SettingDto::class);
		$request->id = $id;

		return $request;
	}

	private function assertReadSuccess(Result $result): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		if ($result->getError()?->getCode() === SettingsProvider::ERROR_NOT_AVAILABLE)
		{
			throw new AccessDeniedException();
		}

		throw new InternalException(new RuntimeException('Failed to read CRM settings.'));
	}

	private function assertWriteSuccess(Result $result): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		$code = $result->getError()?->getCode();
		if ($code === ErrorCode::NOT_FOUND || $code === ErrorCode::ACCESS_DENIED)
		{
			throw new AccessDeniedException();
		}

		$errors = $result->getErrors();
		if ($errors !== [] && array_reduce(
			$errors,
			static fn(bool $valid, Error $error): bool => $valid && $error instanceof SettingValidationError,
			true,
		))
		{
			throw new RequestValidationException(array_map(
				static function (Error $error): Error {
					$path = $error->getCustomData()['path'] ?? null;
					$field = $path === null ? 'value' : 'value.' . $path;

					return new Error($error->getMessage(), $field);
				},
				$errors,
			));
		}

		throw new InternalException(new RuntimeException('Failed to update CRM setting.'));
	}

	private function toDto(array $data): SettingDto
	{
		$meta = new SettingMetaDto();
		$meta->title = $data['meta']['title'];
		$meta->description = $data['meta']['description'];
		$meta->schema = $data['meta']['schema'];

		$dto = new SettingDto();
		$dto->id = $data['id'];
		$dto->value = $data['value'];
		$dto->meta = $meta;

		return $dto;
	}
}
