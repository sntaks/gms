{{--
    Layout fixes for the report pages.

    1. Pages with a side sub-menu (the Finance Reports and Game Reports clusters) show that
       menu as a dropdown up to 1536px instead of Filament's 768px. Beside the main sidebar,
       the side menu would leave a 1280px laptop only ~560px for the report itself.
    2. Breakdown tables scroll sideways inside their own box when they are wider than it,
       instead of being clipped at the section's edge.
--}}
<style>
    @media (min-width: 48rem) and (max-width: 95.98rem) {
        .fi-page.fi-page-has-sub-navigation.fi-page-has-sub-navigation-start .fi-page-sub-navigation-dropdown {
            display: block;
        }

        .fi-page.fi-page-has-sub-navigation.fi-page-has-sub-navigation-start .fi-page-sub-navigation-sidebar-ctn {
            display: none;
        }

        .fi-page.fi-page-has-sub-navigation.fi-page-has-sub-navigation-start .fi-page-main {
            flex-direction: column;
            align-items: stretch;
        }
    }

    .fi-in-table-repeatable {
        overflow-x: auto;
    }
</style>
