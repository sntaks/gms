---
paths:
  - 'app/Filament/Pages/{BaseReportPage,FinanceReportPage,FinanceListReportPage,*Report,*Leaderboard,DepositsPage,PlayerWithdrawalsPage,PurchasesPage}.php'
---

# Filament Pages

## Report pages live in clusters
FinanceReportPage puts every subclass in FinanceReportsCluster; give each new finance report a $navigationGroup from FinanceReportsCluster's constants (Overview, Tax, Activity, Promotions, Bet Shops) and a sort inside that group's range, since sub-menu groups follow the first item's sort. A page that reuses FinanceListReportPage but belongs in the main menu (Deposits, Player Withdrawals, Purchases) must set $cluster = null. Game reports set $cluster = GameReportsCluster::class.

## Finance reports are for admins and the finance role
Gate every finance report, its CSV export and finance-report actions with User::canViewFinanceReports() (super-admin, admin or finance, plus reports.view), never reports.view alone: managers and agents hold reports.view for game reports and must not see the Finance Reports cluster. Owner decision 2026-11-15. A report outside FinanceReportPage (e.g. SportsbookReconciliationReport) sets $cluster itself and uses the same gate.
