<?php
$MESS['SALE_HPS_YANDEX_CHECKOUT'] = 'ЮKassa';
$MESS["SALE_HPS_YANDEX_CHECKOUT_SHOP_ID"] = "shopId";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SHOP_ID_DESC"] = "Скопируйте shopId в личном кабинете ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SHOP_ARTICLE_ID"] = "shopArticleId";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SHOP_ARTICLE_ID_DESC"] = "Это необязательный параметр. Если он понадобится, менеджер ЮKassa сообщит его вам";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SECRET_KEY"] = "Секретный ключ";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SECRET_KEY_DESC"] = "Выпустите и активируйте секретный ключ в личном кабинете ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_RETURN_URL"] = "URL страницы возврата";
$MESS["SALE_HPS_YANDEX_CHECKOUT_RETURN_URL_DESC_2"] = "URL, на который вернется пользователь после оплаты (оставьте пустым для автоматического определения адреса, клиент вернется на страницу, с которой был выполнен переход на оплату)";
$MESS["SALE_HPS_YANDEX_CHECKOUT_PAYMENT_ID"] = "Номер оплаты";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SHOULD_PAY"] = "Сумма к оплате";
$MESS["SALE_HPS_YANDEX_CHECKOUT_PAYMENT_DATE"] = "Дата создания оплаты";
$MESS["SALE_HPS_YANDEX_CHECKOUT_IS_TEST"] = "Тестовый режим";
$MESS["SALE_HPS_YANDEX_CHECKOUT_CHANGE_STATUS_PAY"] = "Автоматически оплачивать заказ при получении успешного статуса оплаты";
$MESS["SALE_HPS_YANDEX_CHECKOUT_PAYMENT_TYPE"] = "Тип платёжной системы";
$MESS["SALE_HPS_YANDEX_CHECKOUT_BUYER_ID"] = "Код покупателя";
$MESS["SALE_HPS_YANDEX_CHECKOUT_RETURN"] = "Возвраты платежей не поддерживаются";
$MESS["SALE_HPS_YANDEX_CHECKOUT_RESTRICTION"] = "Ограничение по сумме платежей зависит от способа оплаты, который выберет покупатель";
$MESS["SALE_HPS_YANDEX_CHECKOUT_COMMISSION"] = "Без комиссии для покупателя";
$MESS["SALE_HPS_YANDEX_CHECKOUT_REFERRER"] = "<a href=\"https://money.yandex.ru/joinups/?source=bitrix24\" target=\"_blank\">Быстрая регистрация</a>";
$MESS["SALE_HPS_YANDEX_CHECKOUT_PAYMENT_DESCRIPTION"] = "Описание транзакции";
$MESS["SALE_HPS_YANDEX_CHECKOUT_PAYMENT_DESCRIPTION_DESC"] = "Описание транзакции (не более 128 символов), которое вы увидите в личном кабинете ЮKassa. Текст может содержать метки: #PAYMENT_ID# - ID оплаты, #ORDER_ID# - ID заказа, #PAYMENT_NUMBER# - номер оплаты, #ORDER_NUMBER# - номер заказа, #USER_EMAIL# - Email покупателя";
$MESS["SALE_HPS_YANDEX_CHECKOUT_PAYMENT_DESCRIPTION_TEMPLATE"] = "Оплата №#PAYMENT_NUMBER# заказа №#ORDER_NUMBER# для #USER_EMAIL#";
$MESS["SALE_HPS_YANDEX_CHECKOUT_RECURRING"] = "Автоплатеж";
$MESS["SALE_HPS_YANDEX_CHECKOUT_RECURRING_DESC"] = "Автоплатежи работают только в CRM-формах";
$MESS["SALE_HPS_YANDEX_CHECKOUT_BANK_CARDS"] = "Оплата картой";
$MESS["SALE_HPS_YANDEX_CHECKOUT_BANK_CARDS_DESCRIPTION"] = "Принимайте платежи по банковским картам. Оплата производится через сервис ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_BANK_CARDS_PUBLIC_DESCRIPTION"] = "Для оплаты картой укажите ее номер, срок действия и CVC. Если вы оплачивали через ЮKassa ранее, вы увидите карту, которую использовали в прошлый раз, и сможете сразу ее выбрать. Для оплаты нужен будет только CVC.";
$MESS["SALE_HPS_YANDEX_CHECKOUT_YOO_MONEY"] = "ЮMoney";
$MESS["SALE_HPS_YANDEX_CHECKOUT_YOO_MONEY_DESCRIPTION"] = "Принимайте электронные платежи от клиентов ЮMoney. Оплата производится через сервис ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBERBANK"] = "SberPay";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBERBANK_DESCRIPTION"] = "Принимайте электронные платежи от клиентов Сбербанк Онлайн. Оплата производится через сервис ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBERBANK_SMS"] = "SberPay по СМС";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBERBANK_SMS_DESCRIPTION"] = "Принимайте электронные платежи от клиентов Сбербанк Онлайн. Для подтверждения платежа клиенту будет отправлено SMS. Оплата производится через сервис ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBERBANK_SMS_PUBLIC_DESCRIPTION"] = "Для оплаты через интернет-банк Сбербанка с подтверждением по СМС через «Мобильный банк» укажите телефон, привязанный к интернет-банку Сбербанка.";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBERBANK_QR"] = "SberPay QR";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBERBANK_QR_DESCRIPTION"] = "Принимайте электронные платежи от клиентов Сбербанк Онлайн. Оплата производится через мобильное приложение";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBERBANK_QR_PUBLIC_DESCRIPTION"] = "При оплате с телефона мобильное приложение СберБанк Онлайн откроется автоматически, при оплате с компьютера нужно отсканировать QR-код.";
$MESS["SALE_HPS_YANDEX_CHECKOUT_ALFABANK"] = "Альфа-Клик";
$MESS["SALE_HPS_YANDEX_CHECKOUT_ALFABANK_DESCRIPTION"] = "Принимайте электронные платежи от клиентов Альфа-Клик. Оплата производится через сервис ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_ALFABANK_PUBLIC_DESCRIPTION"] = "Для оплаты через Альфа-Клик укажите логин или привязанный к Альфа-Клику мобильный телефон. Альфа-Банк пришлет вам сообщение с просьбой подтвердить оплату. Если сообщение не пришло, оплатите заказ через Альфа-Мобайл или личный кабинет Альфа-Клика.";
$MESS["SALE_HPS_YANDEX_CHECKOUT_CASH"] = "Оплата наличными в терминале";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SMART"] = "Умный платеж";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SMART_DESCRIPTION"] = "Принимайте платежи используя Умный платеж. Оплата производится через сервис ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_MOBILE_BALANCE"] = "Баланс телефона";
$MESS["SALE_HPS_YANDEX_CHECKOUT_EMBEDDED"] = "Виджет ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_EMBEDDED_DESCRIPTION"] = "Виджет ЮKassa позволяет оплачивать покупки через банковские карты, Apple Pay, Google Pay и Сбербанк Онлайн. На сайт и платежную страницу будет встроена готовая платежная форма";
$MESS["SALE_HPS_YANDEX_CHECKOUT_TBANK"] = "T-Pay";
$MESS["SALE_HPS_YANDEX_CHECKOUT_TBANK_DESCRIPTION"] = "Принимайте электронные платежи от клиентов Т-Банка. Оплата производится через сервис ЮKassa";
$MESS["SALE_HPS_YANDEX_CHECKOUT_TBANK_PUBLIC_DESCRIPTION"] = "Для оплаты на сайте или в мобильном приложении Т-Банка. Авторизуйтесь для подтверждения платежа на сайте. Чтобы оплатить счёт в мобильном приложении, ведите номер телефона, который подключен к вашему интернет-банку.";
$MESS["SALE_HPS_YANDEX_CHECKOUT_INSTALLMENTS"] = "Заплатить по частям";
$MESS["SALE_HPS_YANDEX_CHECKOUT_INSTALLMENTS_DESCRIPTION"] = "Ваши клиенты сразу получат товар, а вносить деньги будут частями и без переплаты. При этом вы получите всю сумму за товар на следующий день после продажи";
$MESS["SALE_HPS_YANDEX_CHECKOUT_INSTALLMENTS_PUBLIC_DESCRIPTION"] = "Вы можете получить товар прямо сейчас, а вносить деньги за него будете по частям. Кредит выдаётся прямо во время оплаты за несколько минут. Выплата по кредиту будет списываться раз в месяц из кошелька ЮMoney. Если кошелька у вас нет, то он появится в процессе оплаты.";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBP"] = "Система быстрых платежей";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBP_DESCRIPTION"] = "Принимайте электронные платежи через Систему быстрых платежей. Для выполнения платежа клиенту будет нужно отсканировать QR-код";
$MESS["SALE_HPS_YANDEX_CHECKOUT_SBP_PUBLIC_DESCRIPTION"] = "При оплате с телефона мобильное приложение вашего банка откроется автоматически, при оплате с компьютера нужно отсканировать QR-код.";
