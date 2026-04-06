<?php

namespace Modules\NsTranslator\Providers;

use Ns\Classes\AsideMenu;
use Ns\Classes\Hook;
use Illuminate\Support\ServiceProvider;

class NsTranslatorServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Add settings submenu under Settings
        Hook::addFilter( 'ns-dashboard-menus', function ( $menus ) {
            if ( isset( $menus['settings'] ) ) {
                $menus['settings']['childrens'] = [
                    ...$menus['settings']['childrens'],
                    ...AsideMenu::subMenu(
                        label: __m( 'Translator', 'NsTranslator' ),
                        identifier: 'ns-translator',
                        href: nsRoute( 'ns.dashboard.settings', ['settings' => 'ns_translator'] ),
                        permissions: ['manage.options']
                    ),
                ];
            }

            return $menus;
        } );
    }
}
