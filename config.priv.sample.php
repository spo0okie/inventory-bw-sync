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