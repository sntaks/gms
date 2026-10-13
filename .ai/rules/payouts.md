---
paths:
  - 'app/{Models/Payout.php,Policies/PayoutPolicy.php,Services/PayoutDailyCap.php,Filament/Resources/Payouts/**}'
---

# Payouts

## Payout daily cap decides self-approval at creation
payouts.requires_independent_approval is set once in Payout::creating via PayoutDailyCap (config payouts.daily_cap, default 1000; today's non-declined total incl. the new amount). Never recompute it later — the running total grows during the day. PayoutPolicy approve/retry: super-admin + payouts.approve always required; maker may self-approve only when the flag is false. Create payouts inside PayoutDailyCap::withLock to avoid concurrent creations slipping under the cap.
