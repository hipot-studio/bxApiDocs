<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Install\LegacyAgentTokenMigratorAgent;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentTokenGuard;
use Bitrix\Rest\APAuth;
use Bitrix\Rest\OAuth;

/**
 * Кто авторизовал вызов контура. Один источник истины на фильтр, резолвер и действия: иначе
 * решение «кто нас зовёт» размножится по трём местам и разойдётся.
 *
 * - OAuth — Application;
 * - APAuth без passwordId — Unsupported;
 * - APAuth, строки вебхука нет — Unsupported;
 * - APAuth, токен контура (живой или нет) — Contour;
 * - APAuth, legacy-токен агента (User, scope ровно [bizprocdesigner]) — Unsupported;
 * - APAuth, любой другой вебхук — Application: права владельца вебхука по названному шаблону;
 * - всё остальное и отсутствие сервера — Unsupported.
 *
 * Whitelist, а не blacklist, и это несущее. `CRestServer::getPasswordId()` непуст ТОЛЬКО под
 * вебхуком (`rest/lib/apauth/auth.php` — единственное место на весь модуль rest, кладущее
 * `password_id`). Под ключом приложения он пуст — но пуст он и под сессией портала
 * (`sessionauth`), и под типами `token` / `client` модуля `oauth`, и под гостевыми и подписанными
 * типами чужих модулей: `im_guest`, `pull_guest`, `call`, `livechat`, `aiassistant_signed_user`.
 * Поэтому «привязки нет ⇒ это приложение» открыло бы контур всем девяти обработчикам
 * `onRestCheckAuth`, а не только ключу приложения. Решает тип авторизации, и лишь внутри APAuth —
 * чей это вебхук.
 *
 * Токен контура опознаётся по метке владельца, а не по живости
 * {@see AgentTokenGuard::isContourCredential()}: просроченный или отозванный токен остаётся токеном и
 * получает отказ в AgentTokenFilter. Судить его по живости значило бы в час его смерти пустить его
 * ключом приложения — правами владельца по любому шаблону вместо одного привязанного.
 *
 * APAuth без passwordId — не ключ приложения, а отказ. Legacy-батч (`CRestServerBatchItem`) не
 * копирует passwordId, и токен контура может прийти в нём именно так: понизить такой вызов до ключа
 * владельца значило бы снять с токена привязку к шаблону. По той же причине отказ получает вебхук,
 * чьей строки нет: авторизация rest его только что нашла, и пропажа строки — гонка с удалением, а не
 * «метки нет ⇒ ключ приложения» {@see AgentTokenGuard::isKnownCredential()}.
 *
 * Legacy-токен агента — User-вебхук со scope ровно [bizprocdesigner], выданный прежним сервисом под
 * один шаблон. Привязки у него нет, и ключом приложения он получил бы права владельца по любому шаблону;
 * мигратор такие гасит, но пропущенный им токен остаётся живым. Критерий один на мигратор и
 * классификацию {@see LegacyAgentTokenMigratorAgent::isLegacyToken()}.
 *
 * Сервер приходит АРГУМЕНТОМ, а не берётся «текущим». `CRestServer::$instance` — процессная
 * глобаль: выставляется в конструкторе первого построенного сервера, никогда не сбрасывается и с
 * контроллером, который сейчас исполняется, не связана ничем. Читать надо ровно тот источник, что
 * и фильтр контура, — `$controller->getServer()`.
 *
 */
enum ContourCaller
{
	/** Токен контура: System-вебхук с меткой владельца bizprocdesigner. Шаблон можно взять из привязки. */
	case Contour;

	/**
	 * Ключ приложения или вебхук, не являющийся ни токеном контура, ни legacy-токеном агента. Привязки
	 * нет — `templateId` обязателен, права — пользователя, от чьего имени идёт вызов.
	 */
	case Application;

	/**
	 * Всё остальное, включая отсутствие сервера, вебхук без passwordId или без строки и legacy-токен
	 * агента. Отказ.
	 */
	case Unsupported;

	/**
	 * Гард читается только под вебхуком с passwordId: остальные ветки хранилище токенов не трогают.
	 * По умолчанию — гард текущего запроса, общий с фильтрами и действием: строку вебхука вместе со
	 * scope он читает один раз на запрос.
	 */
	public static function of(?\CRestServer $server, ?AgentTokenGuard $guard = null): self
	{
		return match ($server?->getAuthType())
		{
			APAuth\Auth::AUTH_TYPE => self::ofWebhook($server?->getPasswordId(), $guard),
			OAuth\Auth::AUTH_TYPE => self::Application,
			default => self::Unsupported,
		};
	}

	private static function ofWebhook(?string $passwordId, ?AgentTokenGuard $guard): self
	{
		if ($passwordId === null || $passwordId === '')
		{
			return self::Unsupported;
		}

		$guard ??= AgentTokenGuard::forCurrentRequest();

		if (!$guard->isKnownCredential($passwordId))
		{
			return self::Unsupported;
		}

		if ($guard->isContourCredential($passwordId))
		{
			return self::Contour;
		}

		return $guard->isLegacyAgentToken($passwordId) ? self::Unsupported : self::Application;
	}
}
