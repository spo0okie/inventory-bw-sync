# Inventory-BW-sync
Скрипт синхронизации коллекций [Bitwarden](https://bitwarden.com/) ([VaultWarden](https://vaultwarden.net/)) со структурой сервисов Инвентаризации
(Вообще тестировалось только с VaultWarden)  
  
## Синхронизация Инвентаризация -> BW(VW)
  * Повторяет в VW структуру коллекций на повторяющую структуру сервисов из БД Инвентариацзии
  * Выдает на коллекцию права команде сопровождения соответствующего сервиса (ответственный + поддержка)
    * той части команды которая имеет учетки в BW(VW)
    * если состав команды изменяются - требует ручного подтверждения изменения прав на коллекцию (новые коллекцие создает без запроса т.к. они изначально пусты)
    * если никого из состава команды сервиса нет в BW(VW) - коллекция не создается (технически не возможно)

## Требования
  * [VaultWarden](https://github.com/dani-garcia/vaultwarden)
  * [Bitwarden CLI](https://bitwarden.com/help/cli/)
    * Для работы с self-hosted сервером с непубличным сертификатом нужен не бинарник а установленный через NPM
   
## Инструкция
  
