<?php

use Bitrix\Sale;
use Bitrix\Main\Loader;

function sendToTelegram($txt)
{

    $data_log1 = array("message" => $txt);
    $data_log1 = json_encode($data_log1, JSON_UNESCAPED_UNICODE);
    $data_str1 = date('d-m-Y H:i:s') . "\n";
    $data_str1 .= var_to_string($data_log1);
    $data_str1 .= "\n==============================================\n\n\n";
    file_put_contents(($_SERVER['DOCUMENT_ROOT']) . '/local/php_interface/leads/telega.log', $data_str1, FILE_APPEND);

    $token_bot = "";
    $chat_id = "";
    $ch = curl_init('https://api.telegram.org/bot' . $token_bot . '/sendMessage?chat_id=' . $chat_id . '&parse_mode=html&text=' . urlencode($txt)); // URL
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Не возвращать ответ

    curl_exec($ch); // Делаем запрос
    curl_close($ch); // Завершаем сеанс cURL	 
}

// Отправляем данные о заказе в телеграмм
AddEventHandler("sale", "OnSaleComponentOrderOneStepComplete", "OnSaleComponentOrderOneStepCompleteHandler");

function OnSaleComponentOrderOneStepCompleteHandler($orderId)
{
    if (intval($orderId) > 0) {

        Loader::includeModule("sale");

        $arPropertyId = [238, 479, 480]; //RUB, USD, EUR
        $arCode = ['FIO', 'EMAIL', 'PHONE'];
        $arItemId = [];

        // Получаем заказ
        $order = Sale\Order::load($orderId);
        if ($order !== null) {

            $propertyCollection = $order->getPropertyCollection();

            // Получаем свойства заказа
            foreach ($propertyCollection as $arProp) {
                if (strlen($arProp->getValue()) > 0 && in_array($arProp->getField('CODE'), $arCode)) {
                    $arOrder['USER'][$arProp->getField('CODE')] = $arProp->getValue();
                }
            }

            // Получаем товары заказа
            $basket = $order->getBasket();
            $basketItems = $basket->getBasketItems();

            foreach ($basket as $basketItem) {
                $itemId = $basketItem->getField('PRODUCT_ID');
                $arOrder['BASKET'][$itemId]['NAME'] = $basketItem->getField('NAME');
                $arOrder['BASKET'][$itemId]['COUNT'] = $basketItem->getQuantity();
                $arItemId[] = $itemId;
            }

            // Получаем цену на товар из свойств инфоблока
            if (!empty($arItemId)) {

                $propPriceEntity = \Bitrix\Main\ORM\Entity::compileEntity(
                    'propPriceEntity',
                    [
                        (new \Bitrix\Main\ORM\Fields\IntegerField('ID')),
                        (new \Bitrix\Main\ORM\Fields\IntegerField('IBLOCK_PROPERTY_ID')),
                        (new \Bitrix\Main\ORM\Fields\IntegerField('IBLOCK_ELEMENT_ID')),
                        (new \Bitrix\Main\ORM\Fields\IntegerField('VALUE'))
                    ],
                    [
                        'namespace' => 'PropPriceSale',
                        'table_name' => 'b_iblock_element_property'
                    ]
                );

                $res = (new Bitrix\Main\ORM\Query\Query($propPriceEntity))
                    ->setFilter(['=IBLOCK_PROPERTY_ID' => $arPropertyId, '=IBLOCK_ELEMENT_ID' => $arItemId])
                    ->setSelect(['ID', 'IBLOCK_PROPERTY_ID', 'IBLOCK_ELEMENT_ID', 'VALUE'])
                    ->exec();

                while ($item = $res->fetch()) {
                    $arOrder['BASKET'][$item['IBLOCK_ELEMENT_ID']]['PRICE'][$item['IBLOCK_PROPERTY_ID']] = number_format($item['VALUE'], 0, '.', ' ');
                }
            }

            // Формируем сообщение
            $text = "<b>Создан заказ №{$orderId}</b>\n";
            $text .= "\n";
            $text .= "ФИО: {$arOrder['USER']['FIO']}\n";
            $text .= "Email: {$arOrder['USER']['EMAIL']}\n";
            $text .= "Телефон: {$arOrder['USER']['PHONE']}\n";
            $text .= "\n";
            $text .= "<b>Товары</b>\n";

            foreach ($arOrder['BASKET'] as $arItem) {
                $text .= "\n";
                $text .= "{$arItem['NAME']} x <b>{$arItem['COUNT']}</b>\n";
                $text .= "Цена в USD: {$arItem['PRICE'][ $arPropertyId[1] ]}\n";
                $text .= "Цена в EURO: {$arItem['PRICE'][ $arPropertyId[2] ]}\n";
                $text .= "Цена в Руб: {$arItem['PRICE'][ $arPropertyId[0] ]}\n";
            }

            $text .= "\n";
            $price = number_format($order->getField('PRICE'), 0, '.', ' ');
            $text .= "<b>Сумма заказа:</b> {$price} $\n";

            sendToTelegram($text);
        }
    }
}
