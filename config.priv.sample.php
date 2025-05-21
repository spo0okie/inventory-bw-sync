<?php
//как подключиться к инвентори
$webInventory="https://inventory.domain.local/web";
$inventoryAuth = base64_encode("inventory_vw_user:password");

//как подключаться к Vaultwarden
$vwUrl='https://vw.domain.local';
$vwLogin='inventory-vw@corp.ru';
$vwCliPassword='password';
//это не сам пароль, а пароль 600000 раз захешированный при помощи hmac-sha-256 с подсаливанием при помощи логина
//я пытался повторить но забил и вытащил через Chrome Dev Tools что браузер отправляет на сервер при удачном входе в веб морду
$vwWebPassword='600000timesHashedPassword';

//структура внутри VW
//корневая организация (ID)
const ORG_ID="11111111-2222-3333-4444-555555555555";
//Корневая коллекция в которой будет построено дерево сервисов
const COL_ROOT="Сервисы";
//папка вне корневой, где можно размещать общие (доступные всем участникам) пароли
const COL_SHARED="Общие";
