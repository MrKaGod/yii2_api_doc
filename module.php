<?php

namespace app\modules\doc;

class module extends \yii\base\Module
{

    public $ignore = [];
    public $show_modules = null;
    public $controllerNamespace = 'app\modules\doc\controllers';

    public function init()
    {
        parent::init();
    }
}
