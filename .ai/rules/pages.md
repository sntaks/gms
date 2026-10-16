---
paths:
  - 'app/{Concerns/HandlesBetShopWrites.php,Filament/Pages/BetShop*.php,Livewire/BetShop*.php}'
---

# Pages

## Bet shop keys: never retry, never keep the key
POST /bet-shops/{id}/keys is sent with makeRequest(withIdempotencyKey: false): KadiApi stores no response, so a retry issues a second key. On timeout/5xx don't halt or retry; close the modal, refresh the key list and warn to revoke extras. The key lives only in the issuedKey modal's arguments; audit via recordBetShopWrite(), which strips key/pickup_code. Bet shop pages gate on bet-shops.view (not accounts.view: agents have that).
