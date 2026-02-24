<?php
namespace Modules\NsTranslator;

use Illuminate\Support\Facades\Event;
use App\Services\Module;

class NsTranslatorModule extends Module
{
    public function __construct()
    {
        parent::__construct( __FILE__ );
    }
}