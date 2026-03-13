<?php

namespace Bitrix\Rest\V3\Realisation\Controller;

use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Dto\Mapping\FieldMapper;
use Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException;
use Bitrix\Rest\V3\Realisation\Request\Field\GetRequest;
use Bitrix\Rest\V3\Realisation\Request\Field\ListRequest;

final class Field extends RestController
{
	private FieldMapper $mapper;

	public function listAction(ListRequest $request): ListResponse
	{
		if (!class_exists($request->getDtoClass()) || !is_subclass_of($request->getDtoClass(), Dto::class))
		{
			throw new AccessDeniedException();
		}
		$dto = $request->getDtoClass()::create();
		$selectedFields = $request->select ? $request->select->getList() : [];
		$result = $this->getMapper()->mapCollection($dto->getFields()->toArray(), $selectedFields);

		return new ListResponse($result);
	}

	public function getAction(GetRequest $request): GetResponse
	{
		if (!class_exists($request->getDtoClass()) || !is_subclass_of($request->getDtoClass(), Dto::class))
		{
			throw new AccessDeniedException();
		}
		$dto = $request->getDtoClass()::create();
		if (!isset($dto->getFields()[$request->name]))
		{
			throw new FieldNotFoundException($request->name);
		}

		$selectedFields = $request->select ? $request->select->getList() : [];
		$field = $dto->getFields()[$request->name];
		$result = $this->getMapper()->mapOne($field->toArray(), $selectedFields);

		return new GetResponse($result);
	}

	private function getMapper(): FieldMapper
	{
		if (!isset($this->mapper))
		{
			$this->mapper = new FieldMapper();
		}

		return $this->mapper;
	}
}