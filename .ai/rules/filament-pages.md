---
paths:
  - 'app/Filament/Pages/{BaseReportPage,FinanceReportPage,FinanceListReportPage,*Report,*Leaderboard,DepositsPage,PlayerWithdrawalsPage,PurchasesPage}.php'
---

# Filament Pages

## Report pages live in clusters
FinanceReportPage puts every subclass in FinanceReportsCluster; give each new finance report a $navigationGroup from FinanceReportsCluster's constants (Overview, Tax, Activity, Promotions, Bet Shops) and a sort inside that group's range, since sub-menu groups follow the first item's sort. A page that reuses FinanceListReportPage but belongs in the main menu (Deposits, Player Withdrawals, Purchases) must set $cluster = null. Game reports set $cluster = GameReportsCluster::class.
