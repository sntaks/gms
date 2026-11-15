---
paths:
  - 'app/{Models/Site/**,Filament/Pages/Sportsbook*.php,Services/SiteBackofficeClient.php,Concerns/HandlesSiteWrites.php}'
---

# Site Pages

## Kadi site data: read-only, UTC, writes via the signed API
App\Models\Site\* read the site's DB (kadi_site, SELECT-only) and throw on any write; every change goes through SiteBackofficeClient::write() via HandlesSiteWrites (request id kept in the modal; resend the same id when outcomeUnknown()). Site datetimes are UTC: compare with now()->utc() in queries, since a Nairobi Carbon binding is formatted as Nairobi wall time and shifts boundaries by 3h. SiteUser may select only id, name, email, linked_id. Amounts are cents (SportsbookFormat::cents).
